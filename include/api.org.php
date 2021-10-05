<?php

include_once INCLUDE_DIR.'class.api.php';
include_once INCLUDE_DIR.'class.organization.php';

class OrgApiController extends ApiController {

    # Supported arguments -- anything else is an error. These items will be
    # inspected _after_ the fixup() method of the ApiXxxDataParser classes
    # so that all supported input formats should be supported
    function getRequestStructure($format, $data=null) {
        $supported = array(
            "alert", "autorespond", "source", "topicId",
            "attachments" => array("*" =>
                array("name", "type", "data", "encoding", "size")
            ),
            "message", "ip", "priorityId","ticket_id",
            "system_emails" => array(
                "*" => "*"
            ),
            "thread_entry_recipients" => array (
                "*" => array("to", "cc")
            )
        );
        # Fetch dynamic form field names for the given help topic and add
        # the names to the supported request structure
        if (isset($data['topicId'])
                && ($topic = Topic::lookup($data['topicId']))
                && ($forms = $topic->getForms())) {
            foreach ($forms as $form)
                foreach ($form->getDynamicFields() as $field)
                    $supported[] = $field->get('name');
        }

        # Ticket form fields
        # TODO: Support userId for existing user
        if(($form = TicketForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        # User form fields
        if(($form = UserForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        if(!strcasecmp($format, 'email')) {
            $supported = array_merge($supported, array('header', 'mid',
                'emailId', 'to-email-id', 'ticketId', 'reply-to', 'reply-to-name',
                'in-reply-to', 'references', 'thread-type', 'system_emails',
                'mailflags' => array('bounce', 'auto-reply', 'spam', 'viral'),
                'recipients' => array('*' => array('name', 'email', 'source'))
                ));

            $supported['attachments']['*'][] = 'cid';
        }

        return $supported;
    }

    /*
     Validate data - overwrites parent's validator for additional validations.
    */
    function validate(&$data, $format, $strict=true) {
        global $ost;

        //Call parent to Validate the structure
        if(!parent::validate($data, $format, $strict) && $strict)
            $this->exerr(400, __('Unexpected or invalid data received'));

        // Use the settings on the thread entry on the ticket details
        // form to validate the attachments in the email
        $tform = TicketForm::objects()->one()->getForm();
        $messageField = $tform->getField('message');
        $fileField = $messageField->getWidget()->getAttachments();

        // Nuke attachments IF API files are not allowed.
        if (!$messageField->isAttachmentsEnabled())
            $data['attachments'] = array();

        //Validate attachments: Do error checking... soft fail - set the error and pass on the request.
        if ($data['attachments'] && is_array($data['attachments'])) {
            foreach($data['attachments'] as &$file) {
                if ($file['encoding'] && !strcasecmp($file['encoding'], 'base64')) {
                    if(!($file['data'] = base64_decode($file['data'], true)))
                        $file['error'] = sprintf(__('%s: Poorly encoded base64 data'),
                            Format::htmlchars($file['name']));
                }
                // Validate and save immediately
                try {
                    $F = $fileField->uploadAttachment($file);
                    $file['id'] = $F->getId();
                }
                catch (FileUploadError $ex) {
                    $name = $file['name'];
                    $file = array();
                    $file['error'] = Format::htmlchars($name) . ': ' . $ex->getMessage();
                }
            }
            unset($file);
        }

        return true;
    }

    function createOrg($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $org = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $org = $this->processEmail();
        } else {
            # Parse request body
            $data = $this->getRequest($format);
            $errors = array();
            $org = Organization::fromVars($data);
        }

        if(!$org){
            $error = array("code"=>500,"message"=>'Unable to create new organization: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $oldusers = User::objects()->filter(array("org_id"=>$org->getId()));
        if($oldusers){
            foreach($oldusers as $u){
                $org->removeUser($u);
            }
        }
        if(isset($data['users'])){
            foreach($data['users'] as $u){
                $user = User::lookup($u);
                $user->setOrganization($org);
            }
        }

        $result = array("created"=>true,"org_id"=>$org->getId(),"details"=>$org);
        $this->response(201, json_encode($result),$contentType="application/json");
    }

    function getOrg($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $org = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $org = $this->processEmail();
        } else {
            # Parse request body
            $data = $this->getRequest($format);
            $errors = array();
            if(isset($data['org_id'])){
                $org = Organization::lookup($data['org_id']);

            }else{
                $org = $this->_getOrg($data);
            }
        }

        if(!$org){
            $error = array("code"=>500,"message"=>'Unable to get organization: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $this->response(200, json_encode($org),$contentType="application/json");
    }

    function updateOrg($format) {
        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $org = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $org = $this->processEmail();
        } else {
        # Parse request body
            $data = $this->getRequest($format);
            if(isset($data['org_id'])){
                $org = Organization::lookup($data['org_id']);
            }else{
                $error = array("code"=>400,"message"=>'Unable to update organization: no id provided');
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
            $errors = array();
            $isUpdated = $this->_updateOrg($org,$data,$errors);
        }

        if(!$isUpdated){
            $error = array("code"=>500,"message"=>'Unable to update organization: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $result = array("updated"=>$isUpdated,"org_id"=>$org->getId());
        $this->response(200, json_encode($result),$contentType="application/json");
    }

    function deleteOrg($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $org = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $org = $this->processEmail();
        } else {
        # Parse request body
            $data = $this->getRequest($format);
            $errors = array();
            if(isset($data['org_id'])){
                $org = Organization::lookup($data['org_id']);
                if($org)
                    $isDeleted = $org->delete();
                else{
                    $error = array("code"=>400,"message"=>'Unable to delete organization: no org found with given org_id');
                    return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
                }
            }else{
                $error = array("code"=>500,"message"=>'Unable to delete organization: no org_id provided');
                return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
            }
        }

        if(!$isDeleted){
            $error = array("code"=>500,"message"=>'Unable to delete organization: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $result = array("deleted"=>$isDeleted,"org_id"=>$data['org_id']);
        $this->response(200, json_encode($result),$contentType="application/json");
    }

    function orgTickets($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $org = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $org = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            $errors = array();
            $page = 1;
            $limit = 25;

            if(isset($data['org_id'])){
                $org = Organization::lookup($data['org_id']);
            }
            
            if(isset($data['page']) && $data['page'] > 0)
                $page = $data['page'];
            if(isset($data['limit']) && $data['limit'] > 0){
                if($data['limit'] > 100){
                    $error = array("code"=>400,"message"=>'Can not give a limit above 100: bad request body');
                    return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
                }
                $limit = $data['limit'];
            }

            
            if(!$org){
                $error = array("code"=>400,"message"=>'Unable to find org with given id: bad request body');
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
            $pagination = new Pagenate(PHP_INT_MAX, $page, $limit);
            $queue = Ticket::objects()->filter(array("user__org_id"=>$data['org_id']))->limit($pagination->getLimit())->offset($pagination->getStart());;
            $tickets = array();
            foreach($queue as $ticket){
                array_push($tickets,$ticket);
            }
            
        }
        if(!$tickets){
            $error = array("code"=>500,"message"=>'Unable to find ticket to given org: no tickets');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }
        
        $result = array('org'=>$org->getName(),
                        'org_id'=>$org->getId(),
                        "total"=>count($tickets),
                        "result"=>$tickets);
        $this->response(200, json_encode($result),$contentType="application/json");
    }
    

    /* private helper functions */

    function _updateOrg($org,$data,$errors){
        $isUpdated = $org->update($data,$errors);
        if (count($errors)) {
            if(isset($errors['errno']) && $errors['errno'] == 403){
                $error = array("code"=>403,"message"=>'Organization denied');
                return $this->response(403, json_encode(array("error"=>$error)),$contentType="application/json");
            }else{
                $error = array("code"=>400,"message"=>"Unable to update organization: validation errors".":\n"
                .Format::array_implode(": ", "\n", $errors));
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
        }

        $oldusers = User::objects()->filter(array("org_id"=>$org->getId()));
        foreach($oldusers as $u){
            $org->removeUser($u);
        }
        foreach($data['users'] as $u){
            $user = User::lookup($u);
            $user->setOrganization($org);
        }

        return true;
    }

    function _getOrg($data){
        // Init =================================================
        // Declaring variables that we use.
        $orgs = array();        // Orgs array
        $pageNumber = 1;        // Result page number
        $limit = 25;            // Page ticket count limit
        $criteria = null;       // Search criteria
        
        // Check Params =========================================
        // Set page number if given (Default: 1)
        if(isset($data['page']))
            $pageNumber = $data['page'];
        // Set orgs per page limit if given (Default: 25)
        if(isset($data['limit'])){
            // Check if limit exceeds max limit
            if((int)$data['limit'] < 100)
                $limit = $data['limit'];
            else{
                $error = array("code"=>400,"message"=>'Limit can not exceed 100: bad request body');
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
        }

        // Create pagination for query
        $pagination = new Pagenate(Organization::objects()->count(), $pageNumber, $limit);
        $query = Organization::objects()->limit($pagination->getLimit())->offset($pagination->getStart());
        //$page = $pagination->paginateSimple($query);
        
        // Get org information from the page and push it into orgs array
        foreach($query as $org){
            $newarray = json_decode(json_encode($org),true); # Serializing to JSON
            unset($newarray['members']);
            array_push($orgs,$newarray);
        }

        // Clearing up
        $result = array('total'=>count($orgs),'result'=>$orgs);
        return $result;
    }

    function processEmail($data=false) {

        if (!$data)
            $data = $this->getEmailRequest();

        $seen = false;
        if (($entry = ThreadEntry::lookupByEmailHeaders($data, $seen))
            && ($message = $entry->postEmail($data))
        ) {
            if ($message instanceof ThreadEntry) {
                return $message->getThread()->getObject();
            }
            else if ($seen) {
                // Email has been processed previously
                return $entry->getThread()->getObject();
            }
        }

        // Allow continuation of thread without initial message or note
        elseif (($thread = Thread::lookupByEmailHeaders($data))
            && ($message = $thread->postEmail($data))
        ) {
            return $thread->getObject();
        }

        // All emails which do not appear to be part of an existing thread
        // will always create new "Tickets". All other objects will need to
        // be created via the web interface or the API
        return $this->createTicket($data);
    }

}

//Local email piping controller - no API key required!
class PipeApiController extends OrgApiController {

    //Overwrite grandparent's (ApiController) response method.
    function response($code, $resp, $contentType="text/plain") {

        //Use postfix exit codes - instead of HTTP
        switch($code) {
            case 201: //Success
                $exitcode = 0;
                break;
            case 400:
                $exitcode = 66;
                break;
            case 401: /* permission denied */
            case 403:
                $exitcode = 77;
                break;
            case 415:
            case 416:
            case 417:
            case 501:
                $exitcode = 65;
                break;
            case 503:
                $exitcode = 69;
                break;
            case 500: //Server error.
            default: //Temp (unknown) failure - retry
                $exitcode = 75;
        }

        //echo "$code ($exitcode):$resp";
        //We're simply exiting - MTA will take care of the rest based on exit code!
        exit($exitcode);
    }

    function  process() {
        $pipe = new PipeApiController();
        if(($ticket=$pipe->processEmail()))
           return $pipe->response(201, $ticket->getNumber());

        return $pipe->exerr(416, __('Request failed - retry again!'));
    }
}

?>
