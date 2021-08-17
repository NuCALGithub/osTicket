<?php

include_once INCLUDE_DIR.'class.api.php';
include_once INCLUDE_DIR.'class.ticket.php';

class TicketApiController extends ApiController {

    # Supported arguments -- anything else is an error. These items will be
    # inspected _after_ the fixup() method of the ApiXxxDataParser classes
    # so that all supported input formats should be supported
    function getRequestStructure($format, $data=null) {
        $supported = array(
            "alert", "autorespond", "source", "topicId",
            "attachments" => array("*" =>
                array("name", "type", "data", "encoding", "size")
            ),
            "message", "ip", "priorityId",
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


    function create($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            # Parse request body
            $ticket = $this->createTicket($this->getRequest($format));
        }

        if(!$ticket)
            return $this->exerr(500, __("Unable to create new ticket: unknown error"));

        $this->response(201, $ticket->getNumber());
    }

    function get($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            # Parse request body
            $ticket = $this->getTicket($this->getRequest($format));
        }

        if(!$ticket)
            return $this->exerr(500, __("Unable to get ticket details: unknown error"));
        $this->response(200, json_encode($ticket),$contentType="application/json");
    }

    function update($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            # Parse request body
            $ticket = $this->updateTicket($this->getRequest($format));
        }

        if(!$ticket)
            return $this->exerr(500, __("Unable to create new ticket: unknown error"));

        $this->response(200, "Ticket: ".(string) $ticket->getNumber()." has updated succesfully");
    }

    function deleteTicket($format) {
        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            # Parse request body
            $data = $this->getRequest($format);
            $ticket = $this->getTicket($data);
            $isDeleted = false;
            if($ticket != null)
                $isDeleted = $ticket->delete("Deleted by API");
            else
                return $this->exerr(400, __('Unable to find ticket with given number: Bad request body'));
        }

        if(!$isDeleted)
            return $this->exerr(500, __("Unable to delete ticket: unknown error"));

        $this->response(200, "Ticket deleted succesfully");
    }

    function setTicketState($data,$ticket) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $isChanged = $ticket->setState($data['state']);
        if($isChanged == false){
            return $this->exerr(400, __('State not found: Bad request body'));
        }

        if(!$ticket){
            return $this->exerr(500, __("Unable to set ticket state: unknown error"));
        }

        $this->response(200, "Ticket: ".$ticket->getNumber()." status changed to ".$data['state']." succesfully");
    }

    function closeTicket($format){
        $data = $this->getRequest($format);
        $ticket = null;
        # Parse request body
        $ticket = $this->getTicket($data);
        $data += array("state" => "closed");
        $this->setTicketState($data,$ticket);
    }

    function reopenTicket($format){
        $data = $this->getRequest($format);
        $ticket = null;
        # Parse request body
        $ticket = $this->getTicket($data);
        $isClosed = $ticket->isClosed();
        $file = fopen("reopen.txt","w");
        fwrite($file,"0000".PHP_EOL.(int)$isClosed);
        if($isClosed){
            $data += array("state" => "open");
            $this->setTicketState($data,$ticket);
        }else{
            return $this->exerr(400, __("Can not reopen ticket: ticket is not closed"));
        }
    }

    function getStaff($format){
        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $staff = null;
        $staff = $this->_getStaff($this->getRequest($format));
        if(!$staff)
            return $this->exerr(500, __("Unable to find staff: unknown error"));

        $this->response(200, json_encode($staff),$contentType="application/json");
    }

    function getAllStaff($format){
        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $staffs = null;
        $staffs = Staff::objects();
        $res = array();
        foreach($staffs as $staff){
            array_push($res,$staff);
        }
        if(!$res)
            return $this->exerr(500, __("Unable to find staff list: unknown error"));

        $this->response(200, json_encode($res),$contentType="application/json");
    }
    

    function getStaffTickets($format){
        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $staff = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            $staff = $this->_getStaff($data);
            $tickets = Ticket::objects()->filter(array('staff_id'=>$staff->getId()));
            $res = array();
            foreach($tickets as $ticket){
                array_push($res,$ticket);
            }
            # Parse request body
        }

        if(!$res)
            return $this->exerr(500, __("Unable to find staff tickets: unknown error"));

        $this->response(200, json_encode($res),$contentType="application/json");
    }

    function assignTicket($format){
        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $staff = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            $ticket = $this->getTicket($data);
            if(isset($data['staffUserName'])){
                $staff = $this->_getStaff($data);
                $isAssigned = $ticket->assignToStaff($staff->getId(),$data['note']);
                if(!$isAssigned)
                    return $this->exerr(500, __("Unable to assign ticket: unknown error"));
            } else if(isset($data['teamame'])){
                $data=$data;
            }
            # Parse request body
        }
        $this->response(200, "Ticket: ".$data['number']." assigned to ".$data['staffUserName']." succesfully");
    }

    function ticketSearch($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            $ticket = $this->_searchTicket($this->getRequest($format));
        }

        if(!$ticket)
            return $this->exerr(500, __("Unable to find tickets: unknown error"));

        $this->response(200, json_encode($ticket));
    }

    function ticketHaveOrg($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            $data['criteria'] = json_decode("[[\"user__org__name\",\"set\",null]]");
            $ticket = $this->_searchTicket($data);
        }

        if(!$ticket)
            return $this->exerr(500, __("Unable to find tickets: unknown error"));

        $this->response(200, json_encode($ticket));
    }

    function orgTickets($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            if(isset($data['org_id']))
                $org = OrganizationModel::lookup($data['org_id']);
            else
                return $this->exerr(400, __("no org_id provided: bad request body"));
            $data['criteria'] = json_decode("[[\"user__org__name\",\"equal\",\"".$org->getName()."\"]]");
            $ticket = $this->_searchTicket($data);
        }

        if(!$ticket)
            return $this->exerr(500, __("Unable to find tickets: unknown error"));

        $this->response(200, json_encode($ticket));
    }

    function deptTickets($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            if(isset($data['dept_id']))
                $query = Ticket::objects()->filter(array("dept_id"=>$data['dept_id']));
            else
                return $this->exerr(400, __("No dept_id provided: bad request body"));
            $ticket = $this->_searchTicket($data,$query);
        }

        if(!$ticket)
            return $this->exerr(500, __("Unable to find tickets: unknown error"));

        $this->response(200, json_encode($ticket));
    }

    /* private helper functions */


    function _searchTicket($data,$query=null){
        // Init =================================================
        // Declaring variables that we use.
        $tickets = array();     // Tickets array
        $pageNumber = 1;        // Result page number
        $limit = 25;            // Page ticket count limit
        $criteria = null;       // Search criteria
        
        // Check Params =========================================
        // Set criteria if given.
        if(isset($data['criteria'])){
            $criteria = $data['criteria'];
        }
        // Set page number if given (Default: 1)
        if(isset($data['page']))
            $pageNumber = $data['page'];
        // Set ticket per page limit if given (Default: 25)
        if(isset($data['limit'])){
            // Check if limit exceeds max limit
            if((int)$data['limit'] < 100)
                $limit = $data['limit'];
            else
                return $this->exerr(400, __("Limit can not exceed 100: bad request body")); 
        }

        if(!isset($query)){
            // Create a new search query for search
            $query = new AdhocSearch(array(
                'id' => "adhoc,API",
                'root' => 'T',
                'title' => __('Advanced Search API')
             ));
            // Set criteria
            $query->config = $criteria;
            // Create pagination for newly created search query
            $pagination = new Pagenate(PHP_INT_MAX, $pageNumber, $limit);
            $page = $pagination->paginateSimple($query->getQuery());
        }else{
            // Create pagination for existing search query
            $pagination = new Pagenate(PHP_INT_MAX, $pageNumber, $limit);
            $page = $pagination->paginateSimple($query);
        }

        
        
        // Get ticket information from the page and push it into tickets array
        foreach($page as $ticket){
            array_push($tickets,$ticket);
        }

        // Clearing up
        $result = array('total'=>count($tickets),'result'=>$tickets);
        return $result;
    }

    function createTicket($data) {

        # Pull off some meta-data
        $alert       = (bool) (isset($data['alert'])       ? $data['alert']       : true);
        $autorespond = (bool) (isset($data['autorespond']) ? $data['autorespond'] : true);

        # Assign default value to source if not defined, or defined as NULL
        $data['source'] = isset($data['source']) ? $data['source'] : 'API';

        # Create the ticket with the data (attempt to anyway)
        $errors = array();

        $ticket = Ticket::create($data, $errors, $data['source'], $autorespond, $alert);
        # Return errors (?)
        if (count($errors)) {
            if(isset($errors['errno']) && $errors['errno'] == 403)
                return $this->exerr(403, __('Ticket denied'));
            else
                return $this->exerr(
                        400,
                        __("Unable to create new ticket: validation errors").":\n"
                        .Format::array_implode(": ", "\n", $errors)
                        );
        } elseif (!$ticket) {
            return $this->exerr(500, __("Unable to create new ticket: unknown error"));
        }

        return $ticket;
    }

    function getTicket($data) {
        $hasNumber = isset($data['number']);
        $ticket = null;
        if($hasNumber){
            $ticket = Ticket::lookup(array('number' => $data['number']));
            if(!$ticket)
                return $this->exerr(400, __("Unable to find ticket: bad ticket number"));
        }else{
            return $this->exerr(400, __("No number provided: bad request body"));
        }

        return $ticket;
    }

    function updateTicket($data) {
        $tic = $this->getTicket($data);
        $errors = array();
        if($tic != null){
            $tic->update($data,$errors);
            if (count($errors)) {
                if(isset($errors['errno']) && $errors['errno'] == 403)
                    return $this->exerr(403, __('No Permission'));
                else
                    return $this->exerr(
                            400,
                            __("Unable to update ticket: validation errors").":\n"
                            .Format::array_implode(": ", "\n", $errors)
                            );
            } else if ($tic == null) {
                return $this->exerr(500, __("Unable to update ticket: unknown error"));
            }
        } else {
            return $this->exerr(400, __('Unable to find ticket with given number: bad request body'));
        }

        return $tic;
    }

    function _getStaff($data){
        $hasUserName = isset($data['staffUserName']);
        $staff = null;
        if($hasUserName){
            $staff = Staff::lookup(array('username' => $data['staffUserName']));
            if(!$staff)
                return $this->exerr(400, __("Unable to find staff: bad staff username"));
        }else{
            return $this->exerr(400, __("No username provided: bad request body"));
        }

        return $staff;
    }
    
    function replyTicket($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            # Parse request body
            $data = $this->getRequest($format);
            $ticket = $this->getTicket($data);
            $staff = $this->_getStaff($data);
            $errors = array();
            $lock = $ticket->getLock();// ,"lockCode"=>$lock->getCode()
            if($lock != null){
                if($lock->getStaffId()!=$staff->getId()){
                    return $this->exerr(401, __("Action Denied. Ticket is locked by someone else!"));
                }
                $data += array("lockCode"=>$lock->getCode());
            }
            $data += array("staffId"=>$staff->getId(),"poster"=>$staff);
            $isReplied = $ticket->postReply($data,$errors);
        }

        if(!$isReplied)
            return $this->exerr(500, __("Unable to reply to ticket: unknown error"));

        $this->response(200, "Replied to Ticket: ".$data['number']." succesfully");
    }

    function postNoteTicket($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            # Parse request body
            $data = $this->getRequest($format);
            $ticket = $this->getTicket($data);
            if(isset($data['staffUserName'])){
                $staff = $this->_getStaff($data);
            }else {
                $staff = "API";
            }
            if(isset($data['title']) && isset($data['note'])){
                $isAdded = $ticket->logNote($data['title'],$data['note'],$staff,false);
            }
            else{
                return $this->exerr(400, __("Unable to add new note to ticket: bad request body"));
            }
        }

        if($isAdded == false)
            return $this->exerr(500, __("Unable to create new ticket: unknown error"));

        $this->response(200, "Posted note to ticket: ".$ticket->getNumber()." succesfully.");
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
class PipeApiController extends TicketApiController {

    //Overwrite grandparent's (ApiController) response method.
    function response($code, $resp) {

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
