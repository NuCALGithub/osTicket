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


    function create($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

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
        
        $result = array("created"=>true,"ticket_id"=>$ticket->getId());
        $this->response(201, json_encode($result),$contentType="application/json");
    }

    function get($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            # Parse request body
            $ticket = $this->_getTicket($this->getRequest($format));
        }
        if(!$ticket)
            return $this->exerr(500, __("Unable to get ticket details: unknown error"));
        $this->response(200, json_encode($ticket),$contentType="application/json");
    }

    function update($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

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
        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

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
            else{
                $error = array("code"=>400,"message"=>'Unable to find ticket with given number: Bad request body');
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
        }

        if(!$isDeleted){
            $error = array("code"=>500,"message"=>'Unable to delete ticket: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $result = array("deleted"=>true,"ticket_id"=>$data['ticket_id']);
        $this->response(200, json_encode($result),$contentType="application/json");
    }

    function closeTicket($format){
        $data = $this->getRequest($format);
        $ticket = null;
        # Parse request body
        $ticket = $this->getTicket($data);
        $data['state'] = "closed";
        $this->setTicketState($data,$ticket);
    }

    function reopenTicket($format){
        $data = $this->getRequest($format);
        $ticket = null;
        # Parse request body
        $ticket = $this->getTicket($data);
        $isClosed = $ticket->isClosed();
        if($isClosed){
            $data['state'] = "open";
            $this->setTicketState($data,$ticket);
        }else{
            $error = array("code"=>400,"message"=>'Can not reopen ticket: ticket is not closed');
            return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
        }
    }

    function assignTicket($format){
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
            $ticket = $this->getTicket($data);
            if(isset($data['staffUserName']) || isset($data['staff_id'])){
                $staff = $this->_getStaff($data);
                $isAssigned = $ticket->assignToStaff($staff->getId(),$data['note']);
                if(!$isAssigned){
                    $error = array("code"=>500,"message"=>'Unable to assign ticket: unknown error');
                    return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
                }
                $result = array(
                    "assigned"=>true,
                    "ticket"=>$data['ticket_id'],
                    "staff_id"=>$staff->getId(),
                    "staff_name"=>$staff->getFirstName()." ".$staff->getLastName());
            } else if(isset($data['team_id'])){
                $team = Team::lookup($data['team_id']);
                $isAssigned = $ticket->assignToTeam($team->getId(),$data['note']);
                if(!$isAssigned){
                    $error = array("code"=>500,"message"=>'Unable to assign ticket: unknown error');
                    return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
                }
                $result = array(
                    "assigned"=>true,
                    "ticket"=>$data['ticket_id'],
                    "team_id"=>$data['team_id'],
                    "team_name"=>$team->__toString());
            }
            # Parse request body
        }
        
        $this->response(200, json_encode($result),$contentType="application/json");
    }

    function replyTicket($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $ticket = null;
        $result = array();
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
                    $error = array("code"=>401,"message"=>'Action Denied. Ticket is locked by someone else!');
                    return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
                }
                $data += array("lockCode"=>$lock->getCode());
            }
            $data += array("staffId"=>$staff->getId(),"poster"=>$staff);
            $isReplied = $ticket->postReply($data,$errors);
        }

        if(!$isReplied){
            $error = array("code"=>500,"message"=>'Unable to reply to ticket: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $result = array('ticket_id'=>$ticket->getId(),'staff_id'=>$staff->getId(),'isPosted'=>true,'postedReply'=>$isReplied);
        $this->response(200, json_encode($result),$contentType="application/json");
    }

    function postNoteTicket($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $ticket = null;
        $result = array();
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            # Parse request body
            $data = $this->getRequest($format);
            $ticket = $this->getTicket($data);
            $result['ticket_id'] = $ticket->getId();
            if(isset($data['staffUserName']) || isset($data['staff_id'])){
                $staff = $this->_getStaff($data);
                $result['staff_id'] = $staff->getId();
            }else {
                $staff = "API";
                $result['staff_id'] = $staff;
            }
            if(isset($data['title']) && isset($data['note'])){
                $isAdded = $ticket->logNote($data['title'],$data['note'],$staff,false);
            }
            else{
                $error = array("code"=>400,"message"=>'Unable to add new note to ticket: bad request body');
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
        }

        if($isAdded == false){
            $error = array("code"=>500,"message"=>'Unable to create new ticket: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }
        $result['isPosted'] = true;
        $result['postedNote'] = $isAdded;

        $this->response(200, json_encode($result),$contentType="application/json");
    }

    function transferTicket($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            $ticket = $this->getTicket($data);
            $form = new TransferForm($data);
            $errors = array();
            $isTransferred = $ticket->transfer($form,$errors);
            if (count($errors)) {
                if(isset($errors['errno']) && $errors['errno'] == 403){
                    $error = array("code"=>403,"message"=>'Transfer denied');
                    return $this->response(403, json_encode(array("error"=>$error)),$contentType="application/json");
                }else{
                    $error = array("code"=>400,"message"=>"Unable to transef ticket: validation errors".":\n"
                                    .Format::array_implode(": ", "\n", $errors));
                    return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
                }
            }
        }

        if(!$isTransferred){
            $error = array("code"=>500,"message"=>'Unable to transfer ticket: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $result = array("status_code"=>200,"transfer"=>$isTransferred);
        $this->response(200, json_encode($result),$contentType="application/json");
    }

    function ticketSearch($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            //$query = Ticket::lookup($data['criteria']);
            $ticket = $this->_searchTicket($data);
        }

        if(!$ticket){
            $error = array("code"=>500,"message"=>'Unable to find tickets: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $this->response(200, json_encode($ticket),$contentType="application/json");
    }

    function ticketHaveOrg($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            $data['criteria'] = json_decode("[[\"user__org__name\",\"set\",null]]");
            $ticket = $this->_searchTicket($data);
        }

        if(!$ticket){
            $error = array("code"=>500,"message"=>'Unable to find tickets: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $this->response(200, json_encode($ticket),$contentType="application/json");
    }

    function orgTickets($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $ticket = null;
        //$result = array();
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            if(isset($data['org_id']))
                $org = OrganizationModel::lookup($data['org_id']);
            else{
                $error = array("code"=>400,"message"=>'no org_id provided: bad request body');
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
            //$data['criteria'] = json_decode("[[\"user__org__name\",\"equal\",\"".$org->getName()."\"]]");
            $query = Ticket::objects()->filter(array('user__org' => $org->getId()));
            /*$result['total'] = count($ticket);
            $searchedTickets = array();
            foreach($ticket as $t){
                array_push($searchedTickets,$t);
            }
            $result['result'] = $searchedTickets;*/
            $ticket = $this->_searchTicket($data,$query);
        }

        if(!$ticket){
            $error = array("code"=>500,"message"=>'Unable to find tickets: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $this->response(200, json_encode($ticket),$contentType="application/json");
    }

    function deptTickets($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $ticket = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $ticket = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            $query = Ticket::objects()->filter(array("dept_id"=>$data['dept_id']));
            $ticket = $this->_searchTicket($data,$query);
        }

        if(!$ticket){
            $error = array("code"=>500,"message"=>'Unable to find tickets: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $this->response(200, json_encode($ticket),$contentType="application/json");
    }

    function threadAction($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $threadEntry = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $threadEntry = $this->processEmail();
        } else {
            # Parse request body
            $data = $this->getRequest($format);
            $threadEntry = $this->triggerThreadAction($data);
        }

        if(!$threadEntry){
            $error = array("code"=>500,"message"=>'Unable to find thread: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $this->response(200, json_encode($threadEntry),$contentType="application/json");
    }

    function threadDelete($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $threadEntry = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $threadEntry = $this->processEmail();
        } else {
            # Parse request body
            $data = $this->getRequest($format);
            $data['action'] = 'delete';
            $threadEntry = $this->triggerThreadAction($data);
        }

        if(!$threadEntry){
            $error = array("code"=>500,"message"=>'Unable to find thread: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $this->response(200, json_encode($threadEntry),$contentType="application/json");
    }

    function threadGet($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $threadEntry = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $threadEntry = $this->processEmail();
        } else {
            # Parse request body
            $data = $this->getRequest($format);
            $data['action'] = 'getThread';
            $threadEntry = $this->triggerThreadAction($data);
        }

        if(!$threadEntry){
            $error = array("code"=>500,"message"=>'Unable to find thread: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $this->response(200, json_encode($threadEntry),$contentType="application/json");
    }
    

    /* private helper functions */

    function setTicketState($data,$ticket) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $isChanged = $ticket->setState($data['state']);
        if($isChanged == false){
            $error = array("code"=>500,"message"=>'State not found: Bad request body');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        if(!$ticket){
            $error = array("code"=>500,"message"=>'Unable to set ticket state: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $result = array("changed"=>$isChanged,"state"=>$data['state'],"ticket_id"=>$ticket->getId());
        $this->response(200, json_encode($result),$contentType="application/json");
    }

    function updateEntry($thread_id,$newThreadBody,$title,$thisstaff,$guard=false) {

        $old = ThreadEntry::lookup($thread_id);
        $new = ThreadEntryBody::fromFormattedText($newThreadBody, $old->format);
        if ($new->getClean() == $old->getBody())
            // No update was performed
            return $old;

        $entry = ThreadEntry::create(array(
            // Copy most information from the old entry
            'poster' => $old->poster,
            'userId' => $old->user_id,
            'staffId' => $old->staff_id,
            'type' => $old->type,
            'threadId' => $old->thread_id,
            'recipients' => $old->recipients,

            // Connect the new entry to be a child of the previous
            'pid' => $old->id,

            // Add in new stuff
            'title' => Format::htmlchars($title),
            'body' => $new,
            'ip_address' => $_SERVER['REMOTE_ADDR'],
        ));

        if (!$entry)
            return false;

        // Move the attachments to the new entry
        $old->attachments->filter(array(
            'inline' => false,
        ))->update(array(
            'object_id' => $entry->id
        ));

        // Note, anything that points to the $old entry as PID should remain
        // that way for email header lookups and such to remain consistent

        if ($old->flags & ThreadEntry::FLAG_EDITED
            // If editing another person's edit, make a new entry
            and ($old->editor == $thisstaff->getId() && $old->editor_type == 'S')
            and !($old->flags & ThreadEntry::FLAG_GUARDED)
        ) {
            // Replace previous edit --------------------------
            $original = $old->getParent();
            // Link the new entry to the old id
            $entry->pid = $old->pid;
            // Drop the previous edit, and base this edit off the original
            $old->delete();
            $old = $original;
        }

        // Mark the new entry as edited (but not hidden nor guarded)
        $entry->flags = ($old->flags & ~(ThreadEntry::FLAG_HIDDEN | ThreadEntry::FLAG_GUARDED))
            | ThreadEntry::FLAG_EDITED;

        // Guard against deletes on future edit if requested. This is done
        // if an email was triggered by the last edit. In such a case, it
        // should not be replaced by a subsequent edit.
        if ($guard)
            $entry->flags |= ThreadEntry::FLAG_GUARDED;

        // Log the editor
        $entry->editor = $thisstaff->getId();
        $entry->editor_type = 'S';

        // Sort in the same place in the thread
        $entry->created = $old->created;
        $entry->updated = SqlFunction::NOW();
        $entry->save(true);

        // Hide the old entry from the object thread
        $old->flags |= ThreadEntry::FLAG_HIDDEN;
        $old->save();

        return $entry;
    }

    function resend($threadEntry,$data,$staff) {
        global $cfg;

        if (!($object = $threadEntry->getThread()->getObject()))
            return false;

        //$vars = $_POST;
        $dept = $object->getDept();
        $poster = $threadEntry->getStaff();

        if ($staff && $data['signature'] == 'mine')
            $signature = $staff->getSignature();
        elseif ($poster && $data['signature'] == 'theirs')
            $signature = $poster->getSignature();
        elseif ($data['signature'] == 'dept' && $dept && $dept->isPublic())
            $signature = $dept->getSignature();
        else
            $signature = '';

        $variables = array(
            'response' => $threadEntry,
            'signature' => $signature,
            'staff' => $threadEntry->getStaff(),
            'poster' => $threadEntry->getStaff());
        $options = array('thread' => $threadEntry);

        // Resend response to collabs
        if (($object instanceof Ticket)
                && ($email=$dept->getEmail())
                && ($tpl = $dept->getTemplate())
                && ($msg=$tpl->getReplyMsgTemplate())) {

            $recipients = json_decode($threadEntry->recipients, true);

            $msg = $object->replaceVars($msg->asArray(),
                $variables + array('recipient' => $object->getOwner()));

            $attachments = $cfg->emailAttachments()
                ? $threadEntry->getAttachments() : array();
            $email->send($object->getOwner(), $msg['subj'], $msg['body'],
                $attachments, $options, $recipients);
        }
        // TODO: Add an option to the dialog
        if ($object instanceof Task)
          $object->notifyCollaborators($threadEntry, array('signature' => $signature));

        // Log an event that the item was resent
        $object->logEvent('resent', array('entry' => $threadEntry->id),$staff);

        $type = array('type' => 'resent');
        Signal::send('object.edited', $object, $type);

        // Flag the entry as resent
        $threadEntry->flags |= ThreadEntry::FLAG_RESENT;
        $threadEntry->save();
    }

    function triggerThreadAction($data) {
        // Setting variables
        $threadEntry = null;
        if($_SERVER['REQUEST_METHOD'] != 'GET')
            $staff = $this->_getStaff($data);
        $body = null;
        $title = null;
        $action = null;

        // Assigning variables if given
        if(isset($data['thread_id']) && isset($data['action'])){
            $threadEntry = ThreadEntry::lookup($data['thread_id']);
            // Check if thread entry exists
            if(!$threadEntry){
                $error = array("code"=>400,"message"=>'No thread entry found with given thread_id: bad request body');
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
            // assign vars
            $body = $data['body'];
            $action = $data['action'];
            if(isset($data['title']))
                $title = $data['title'];
        }else{
            $error = array("code"=>400,"message"=>'No thread_id/action given: bad request body');
            return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        // Take proper action
        switch($action){
            case 'edit':
                if($body == null){
                    $error = array("code"=>400,"message"=>'No thread entry body given: bad request body');
                    return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
                }
                $threadEntry = $this->updateEntry($threadEntry->getId(),$body,$title,$staff);
                $this->response(200, 
                                json_encode(array("action"=>"edit",
                                                  "status"=>"edited",
                                                  "thread_id"=>$threadEntry->getId(),
                                                  "thread"=>$threadEntry))
                                ,$contentType="application/json");
                break;
            case 'edit_resend':
                if($body == null){
                    $error = array("code"=>400,"message"=>'No thread entry body given: bad request body');
                    return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
                }
                $threadEntry = $this->updateEntry($threadEntry->getId(),$body,$title,$staff);
                $this->resend($threadEntry,$data,$staff);
                $this->response(200, 
                                json_encode(array("action"=>"edit_resend",
                                                  "status"=>"edited/resent",
                                                  "thread_id"=>$threadEntry->getId(),
                                                  "thread"=>$threadEntry))
                                ,$contentType="application/json");
                break;
            case 'resend':
                $this->resend($threadEntry,$data,$staff);
                $this->response(200, 
                                json_encode(array("action"=>"resend",
                                                  "status"=>"resent",
                                                  "thread_id"=>$threadEntry->getId(),
                                                  "thread"=>$threadEntry))
                                ,$contentType="application/json");
                break;
            case 'delete':
                if($_SERVER['REQUEST_METHOD'] == 'DELETE'){
                    $threadEntry->delete();
                    $this->response(200, 
                        json_encode(array("action"=>"delete",
                                          "status"=>"deleted",
                                          "thread_id"=>$threadEntry->getId()))
                                          ,$contentType="application/json");
                } else{
                    $error = array("code"=>400,"message"=>'HTTP method not supported for: '.$action);
                    return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
                }
                break;
            case 'getThread':
                $this->response(200, json_encode($threadEntry),$contentType="application/json");
                break;
            default:
                $error = array("code"=>400,"message"=>'No action found: bad request body');
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
                break;
        }
        return $threadEntry;
    }

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
            else{
                $error = array("code"=>400,"message"=>'Limit can not exceed 100: bad request body');
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
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
            if(isset($errors['errno']) && $errors['errno'] == 403){
                $error = array("code"=>403,"message"=>'Ticket denied');
                return $this->response(403, json_encode(array("error"=>$error)),$contentType="application/json");
            }else{
                $error = array("code"=>400,"message"=>"Unable to create new ticket: validation errors".":\n"
                .Format::array_implode(": ", "\n", $errors));
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
        } elseif (!$ticket) {
            $error = array("code"=>500,"message"=>'Unable to create new ticket: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        return $ticket;
    }

    function getTicket($data) {
        $hasId = isset($data['ticket_id']);
        $ticket = null;
        $query = array();
        if($hasId){
            $ticket = Ticket::lookup($data['ticket_id']);
            if(!$ticket){
                $error = array("code"=>400,"message"=>'Unable to find ticket: bad ticket id');
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
        }else{
            $error = array("code"=>400,"message"=>'No id provided: bad request body');
            return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        return $ticket;
    }

    function _getTicket($data) {
        $hasId = isset($data['ticket_id']);
        $ticket = null;
        $query = array();
        if($hasId){
            $ticket = Ticket::lookup($data['ticket_id']);
            if(!$ticket){
                $error = array("code"=>400,"message"=>'Unable to find ticket: bad ticket id');
                return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
            }
            $types = array('M', 'R', 'N');
            $threadTypes=array('M'=>'message','R'=>'response', 'N'=>'note');
            $thread = $ticket->getThreadEntries($types);
            $a = array();
            foreach ($thread as $tentry) {
                array_push($a , $tentry);
            }
            //$ticket = array("ticket"=>$ticket,"thread_entries"=>$a);
            $ticket = json_decode(json_encode($ticket),true);
            $ticket['thread_entries'] = $a;
        }else{
            $error = array("code"=>400,"message"=>'No id provided: bad request body');
            return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        return $ticket;
    }

    function updateTicket($data) {
        $tic = $this->getTicket($data);
        $errors = array();
        if($tic != null){
            $tic->update($data,$errors);
            if (count($errors)) {
                if(isset($errors['errno']) && $errors['errno'] == 403){
                    $error = array("code"=>403,"message"=>'No Permission');
                    return $this->response(403, json_encode(array("error"=>$error)),$contentType="application/json");
                }else{
                    $error = array("code"=>400,"message"=>"Unable to update ticket: validation errors".":\n"
                    .Format::array_implode(": ", "\n", $errors));
                    return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
                }
            } else if ($tic == null) {
                $error = array("code"=>500,"message"=>'Unable to update ticket: unknown error');
                return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
            }
        } else {
            $error = array("code"=>400,"message"=>'Unable to find ticket with given id: bad request body');
            return $this->response(400, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        return $tic;
    }

    function _getStaff($data){
        $staff = null;
        if(isset($data['staffUserName'])){
            $staff = Staff::lookup(array('username' => $data['staffUserName']));
        }else if(isset($data['staff_id'])){
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
