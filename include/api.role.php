<?php

include_once INCLUDE_DIR.'class.api.php';
include_once INCLUDE_DIR.'class.role.php';

class RoleApiController extends ApiController {

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

    function getRole($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $role = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $role = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            if(isset($data['role_id']))
                $role = Role::lookup($data['role_id']);
            else{
                $role = $this->_getRole($data);
            }
        }

        if(!$role)
            return $this->exerr(500, __("Unable to find role: unknown error"));

        $this->response(200, json_encode($role));
    }

    function createRole($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $role = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $role = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            $role = Role::create();
            $errors = array();
            $role->update($data,$errors);
            if (count($errors)) {
                if(isset($errors['errno']) && $errors['errno'] == 403)
                    return $this->exerr(403, __('Role denied'));
                else
                    return $this->exerr(
                            400,
                            __("Unable to create new role: validation errors").":\n"
                            .Format::array_implode(": ", "\n", $errors)
                            );
            } 
        }
        if(!$role)
            return $this->exerr(500, __("Unable to find role: unknown error"));

        $this->response(200, json_encode($role));
    }

    function updateRole($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $role = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $role = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            if(isset($data['role_id']))
                $role = Role::lookup($data['role_id']);
            else
                return $this->exerr(400, __('No role_id provided: bad request body'));
            $errors = array();
            $role->update($data,$errors);
            if (count($errors)) {
                if(isset($errors['errno']) && $errors['errno'] == 403)
                    return $this->exerr(403, __('Role denied'));
                else
                    return $this->exerr(
                            400,
                            __("Unable to create new role: validation errors").":\n"
                            .Format::array_implode(": ", "\n", $errors)
                            );
            } 
        }
        if(!$role)
            return $this->exerr(500, __("Unable to find role: unknown error"));

        $this->response(200, json_encode($role));
    }

    function deleteRole($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $role = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $role = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            if(isset($data['role_id']))
                $role = Role::lookup($data['role_id']);
            else
                return $this->exerr(400, __('No role_id provided: bad request body'));
            $isDeleted = $role->delete();
            
        }
        if(!$isDeleted)
            return $this->exerr(500, __("Unable to delete role: unknown error"));

        $this->response(200, json_encode($isDeleted));
    }
    

    /* private helper functions */

    function _getRole($data){
        // Init =================================================
        // Declaring variables that we use.
        $roles = array();        // roles array
        $pageNumber = 1;        // Result page number
        $limit = 25;            // Page ticket count limit
        $criteria = null;       // Search criteria
        
        // Check Params =========================================
        // Set page number if given (Default: 1)
        if(isset($data['page']))
            $pageNumber = $data['page'];
        // Set roles per page limit if given (Default: 25)
        if(isset($data['limit'])){
            // Check if limit exceeds max limit
            if((int)$data['limit'] < 100)
                $limit = $data['limit'];
            else
                return $this->exerr(400, __("Limit can not exceed 100: bad request body")); 
        }

        // Create pagination for query
        $pagination = new Pagenate(Role::objects()->count(), $pageNumber, $limit);
        $query = Role::objects()->filter(array("flags" => true))->limit($pagination->getLimit())->offset($pagination->getStart());
        //$page = $pagination->paginateSimple($query);
        
        // Get role information from the page and push it into roles array
        foreach($query as $role){
            array_push($roles,$role);
        }

        // Clearing up
        $result = array('total'=>count($roles),'result'=>$roles);
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
class PipeApiController extends RoleApiController {

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
