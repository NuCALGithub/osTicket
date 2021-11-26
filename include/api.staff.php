<?php

include_once INCLUDE_DIR.'class.api.php';
include_once INCLUDE_DIR.'class.ticket.php';
include_once INCLUDE_DIR.'class.report.php';

class StaffApiController extends ApiController {

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


    function getStaff($format){
        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $staff = null;
        $staff = $this->_getStaff($this->getRequest($format));
        if(!$staff){
            $error = array("code"=>500,"message"=>'Unable to find staff: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $this->response(200, json_encode($staff),$contentType="application/json");
    }

    function getAllStaff($format){
        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $staffs = null;
        $staffs = Staff::objects();
        $res = array();
        foreach($staffs as $staff){
            array_push($res,$staff);
        }
        if(!$res){
            $error = array("code"=>500,"message"=>'Unable to find staff list: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $this->response(200, json_encode($res),$contentType="application/json");
    }
    
    function getStaffTickets($format){
        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $staff = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            $staff = $this->_getStaff($data);
            $page = 1;
            $limit = 25;
            if(isset($data['page']) && $data['page'] > 0)
                $page = $data['page'];

            if(isset($data['limit']) && $data['limit'] > 0){
                if($data['limit'] > 100){
                    $error = array("code"=>400,"message"=>'Can not give a limit above 100: bad request body');
                    return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
                }
                $limit = $data['limit'];
            }
            $pagination = new Pagenate(PHP_INT_MAX, $page, $limit);
            //$page = $pagination->paginateSimple($query->getQuery());
            $page = Ticket::objects()->filter(array('staff_id'=>$staff->getId()))->limit($pagination->getLimit())->offset($pagination->getStart());
            $tickets = array();
            foreach($page as $ticket){
                array_push($tickets,$ticket);
            }

            $result = array("total"=>count($tickets),"staff_id"=>$staff->getId(),"result"=>$tickets);
            # Parse request body
        }

        if(!$result){
            $error = array("code"=>500,"message"=>'Unable to find staff tickets: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $this->response(200, json_encode($result),$contentType="application/json");
    }

    function createStaff($format){
        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $data = $this->getRequest($format);
        $staff = Staff::create();
        $data['id'] = $staff->getId();
        $isCreated = $this->_updateStaff($staff,$data);

        if(!$isCreated){
            $error = array("code"=>500,"message"=>'Unable to create staff: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $this->response(200, json_encode($staff),$contentType="application/json");
    }

    function updateStaff($format){
        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }
        $data = $this->getRequest($format);
        $staff = $this->_getStaff($data);
        $data['id'] = $staff->getId();
        $isUpdated = $this->_updateStaff($staff,$data);
        if(!$isUpdated){
            $error = array("code"=>500,"message"=>'Unable to update staff: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $this->response(200, json_encode($staff),$contentType="application/json");
    }
    
    function deleteStaff($format){
        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $data = $this->getRequest($format);
        $staff = $this->_getStaff($data);
        $username = $staff->getUserName();
        $isDeleted = $staff->APIdelete();
        if(!$isDeleted){
            $error = array("code"=>500,"message"=>'Unable to delete staff: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $result = array("deleted"=>$isDeleted,"staff_id"=>$data['staff_id'],"staff"=>$username);
        $this->response(200, json_encode($result),$contentType="application/json");
    }

    function getPerms($format){
        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $perms = array('perms'=> array(
            User::PERM_CREATE,
            User::PERM_EDIT,
            User::PERM_DELETE,
            User::PERM_MANAGE,
            User::PERM_DIRECTORY,
            Organization::PERM_CREATE,
            Organization::PERM_EDIT,
            Organization::PERM_DELETE,
            FAQ::PERM_MANAGE,
            Dept::PERM_DEPT,
            Staff::PERM_STAFF,
            Email::PERM_BANLIST,
            SearchBackend::PERM_EVERYTHING,
            ReportModel::PERM_AGENTS
        ));

        $this->response(200, json_encode($perms),$contentType="application/json");
    }
    

    /* private helper functions */


    function _getStaff($data){
        $staff = null;
        if(isset($data['staffUserName'])){
            $staff = Staff::lookup(array('username' => $data['staffUserName']));
        }else if(isset($data['staff_id']) && $data['staff_id'] != 1){
            $staff = Staff::lookup($data['staff_id']);
        }else if(!$staff){
            $error = array("code"=>400,"message"=>'Unable to find staff: bad staff username or staff id');
            return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
        }else{
            $error = array("code"=>400,"message"=>'No username or id provided: bad request body');
            return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
        }
        return $staff;
    }

    function _updateStaff($staff,$data){
        $errors = array();

        $data['phone'] = isset($data['phone']) ? $data['phone'] : $staff->getVar('phone');
        $data['phone_ext'] = isset($data['phone']) ? $data['phone'] : $staff->getVar('phone');
        $data['mobile'] = isset($data['mobile']) ? $data['mobile'] : $staff->getVar('mobile');
        $data['islocked'] = isset($data['islocked']) ? $data['islocked'] : $staff->active;
        $data['isadmin'] = isset($data['isadmin']) ? $data['isadmin'] : $staff->isAdmin();
        $data['assigned_only'] = isset($data['assigned_only']) ? $data['assigned_only'] : $staff->assigned_only;
        $data['onvacation'] = isset($data['onvacation']) ? $data['onvacation'] : $staff->onvacation;
        $data['notes'] = isset($data['notes']) ? $data['notes'] : $staff->notes;
        $data['assign_use_pri_role'] = isset($data['assign_use_pri_role']) ? $data['assign_use_pri_role'] : $staff->usePrimaryRoleOnAssignment();
        
        $dept_access = array();
        $dept_access_role = array();
        $dept_access_alerts = array();
        $perms = array();

        foreach($staff->dept_access as $a){
            array_push($dept_access,$a->role_id);
            $dept_access_role[$a->dept_id] = $a->role_id;
            $dept_access_alerts[$a->dept_id] = $a->isAlertsEnabled();
        }

        if($staff->getPermission()->perms != null){
            foreach(array_keys($staff->getPermission()->perms) as $p){
                array_push($perms,$p);
            }
        }

        $data['dept_access'] = isset($data['dept_access']) ? $data['dept_access'] : $dept_access;
        $data['dept_access_role'] = isset($data['dept_access_role']) ? $data['dept_access_role'] : $dept_access_role;
        $data['dept_access_alerts'] = isset($data['dept_access_alerts']) ? $data['dept_access_alerts'] : $dept_access_alerts;
        $data['perms'] = isset($data['perms']) ? $data['perms'] : $perms;

        $isUpdated = $staff->update($data,$errors);
        if (count($errors)) {
            if(isset($errors['errno']) && $errors['errno'] == 403){
                $error = array("code"=>403,"message"=>'staff denied');
                return $this->response(403, json_encode(array("error"=>$error)),$contentType="application/json");
            }else{
                $error = array("code"=>400,"message"=>"Unable to take action: validation errors".":\n"
                .Format::array_implode(": ", "\n", $errors));
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
        }
        return $isUpdated;
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
class PipeApiController extends StaffApiController {

    //Overwrite grandparent's (ApiController) response method.
    function response($code, $resp,$contentType="text/plain") {

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
