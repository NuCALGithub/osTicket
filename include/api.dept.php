<?php

include_once INCLUDE_DIR.'class.api.php';
include_once INCLUDE_DIR.'class.dept.php';

class DeptApiController extends ApiController {

    # Supported arguments -- anything else is an error. These items will be
    # inspected _after_ the fixup() method of the ApiXxxDataParser classes
    # so that all supported input formats should be supported
    function getRequestStructure($format, $data=null) {
        $supported = array(
            "page", "limit", "dept_id", "pid",
            "name", "status", "ispublic","sla_id","schedule_id",
            "manager_id","assignment_flag","email_id","tpl_id",
            "autoresp_email_id","group_membership","signature",
            "members" => array("*"),
            "member_role" => array("*"),
            "member_alerts" => array("*")
        );
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

    function createDept($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $dept = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $dept = $this->processEmail();
        } else {
            # Parse request body
            $data = $this->getRequest($format);
            $errors = array();
            $dept = Dept::create();
            $isCreated = $this->_updateDept($dept,$data,$errors); 
        }

        if(!$dept){
            $error = array("code"=>500,"message"=>'Unable to create new department: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $result = array("created"=>$isCreated,"details"=>$dept);
        $this->response(201, json_encode($result),$contentType="application/json");
    }

    function updateDept($format) {
        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $dept = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $dept = $this->processEmail();
        } else {
        # Parse request body
            $data = $this->getRequest($format);
            $errors = array();
            if(isset($data['dept_id'])){
                $dept = Dept::lookup($data['dept_id']);
                $data['id'] = $data['dept_id'];
            }else{
                $error = array("code"=>400,"message"=>'Unable to update department: no id provided');
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
            
            $isUpdated = $this->_updateDept($dept,$data,$errors);
        }

        if(!$isUpdated){
            $error = array("code"=>400,"message"=>'Unable to update dept: unknown error');
            return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $result = array("updated"=>$isUpdated,"dept_id"=>$dept->getId());
        $this->response(200, json_encode($result),$contentType="application/json");
    }

    function deleteDept($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $dept = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $dept = $this->processEmail();
        } else {
        # Parse request body
            $data = $this->getRequest($format);
            if(isset($data['dept_id'])){
                $dept = Dept::lookup($data['dept_id']);
                if($dept)
                    $isDeleted = $dept->delete();
                else{
                    $error = array("code"=>400,"message"=>'Unable to delete department: no dept found with given dept_id');
                    return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
                }
            }else{
                $error = array("code"=>400,"message"=>'Unable to delete department: no dept_id provided');
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
        }

        if(!$isDeleted){
            $error = array("code"=>500,"message"=>'Unable to delete dept: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $result = array("deleted"=>$isDeleted,"dept_id"=>$data['dept_id']);
        $this->response(200, json_encode($result),$contentType="application/json");
    }

    function deptTicketStatus($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $dept = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $dept = $this->processEmail();
        } else {
        # Parse request body
            $data = $this->getRequest($format);
            if(isset($data['dept_id'])){
                $dept = Dept::lookup($data['dept_id']);
                if($dept){
                    $openTickets = Ticket::objects()->filter(array("dept_id"=>$dept->getId(),"status_id"=>"1"));
                    $resolvedTickets = Ticket::objects()->filter(array("dept_id"=>$dept->getId(),"status_id"=>"2"));
                    $closedTickets = Ticket::objects()->filter(array("dept_id"=>$dept->getId(),"status_id"=>"3"));
                    #$isDeleted = $dept->delete();
                    
                }
                else{
                    $error = array("code"=>400,"message"=>'Unable to find department: no dept found with given dept_id');
                    return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
                }
            }else{
                $error = array("code"=>400,"message"=>'Unable to find department: no dept_id provided');
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
        }

        /*if(!$isDeleted){
            $error = array("code"=>500,"message"=>'Unable to delete dept: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }*/

        $result = array("department"=>$dept->getName(),"dept_id"=>$data['dept_id'],"open_tickets"=>count($openTickets),"resolved_tickets"=>count($resolvedTickets),"closed_tickets"=>count($closedTickets));
        $this->response(200, json_encode($result),$contentType="application/json");
    }
    

    /* private helper functions */

    function _updateDept($dept,$data,$errors){
        if(!$dept){
            $error = array("code"=>400,"message"=>'No department found with given id: bad request body');
            return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
        }
        $isUpdated = $dept->update($data,$errors);
        if (count($errors)) {
            if(isset($errors['errno']) && $errors['errno'] == 403){
                $error = array("code"=>403,"message"=>'Department denied');
                return $this->response(403, json_encode(array("error"=>$error)),$contentType="application/json");
            }else{
                $error = array("code"=>400,"message"=>"Unable to take action: validation errors".":\n"
                .Format::array_implode(": ", "\n", $errors));
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
        }
        return true;
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
class PipeApiController extends DeptApiController {

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
