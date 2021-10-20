<?php

include_once INCLUDE_DIR.'class.api.php';
include_once INCLUDE_DIR.'class.ticket.php';
include_once INCLUDE_DIR.'class.report.php';

class StatsApiController extends ApiController {

    # Supported arguments -- anything else is an error. These items will be
    # inspected _after_ the fixup() method of the ApiXxxDataParser classes
    # so that all supported input formats should be supported
    function getRequestStructure($format, $data=null) {
        $supported = array(
            "from", "to", "group"
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

    function getStats($format) {

        if(!($key=$this->requireApiKey()) || !$key->canCreateTickets()){
            $error = array("code"=>401,"message"=>'API key not authorized');
            return $this->response(401, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $stats = null;
        if(!strcasecmp($format, 'email')) {
            # Handle remote piped emails - could be a reply...etc.
            $stats = $this->processEmail();
        } else {
            $data = $this->getRequest($format);
            $stats = $this->_getStats($data);
        }

        if(!$stats){
            $error = array("code"=>500,"message"=>'Unable to find statistics: unknown error');
            return $this->response(500, json_encode(array("error"=>$error)),$contentType="application/json");
        }

        $this->response(200, json_encode($stats),$contentType="application/json");
    }

    

    /* private helper functions */

    function _getStats($data){

        // Declare needed variables
        $errors = array();
        $groups = array("dept","topic","staff");
        $from = null;
        $to = "now";

        // Check if the `from` date is set
        if(isset($data['from'])){
            // Check if the date is correct
            if($this->dateChecker($data['from'])){
                $from = $data['from'];
            } else {
                $errors = array("code"=>400,"message"=>'"from" is not not a valid date: bad request');
            }
            
        } else{
            $errors = array("code"=>400,"message"=>'No "from" provided: bad request');
        }

        // Check if the `to` date is set
        if(isset($data['to'])){
            // Check if the date is corerct and after `from` date
            if($this->dateChecker($data['to']) || $data['to'] == "now"){
                if(strtotime($data['to']) > strtotime($data['from']))
                    $to = $data['to'];
                else
                    $errors = array("code"=>400,"message"=>'"to" date is can not be before "from" date: bad request');
            } else {
                $errors = array("code"=>400,"message"=>'"to" is not a valid date: bad request');
            }
            
        }

        // Get the report
        if($from != null){
            $report = new OverviewReport($from,$to);
        }
       
        // Get the tabular data for that report
        if($report){
            if(isset($data['group'])){
                if(in_array($data['group'],$groups))
                    $fffff = $this->getTabularData($data,$report,$errors,$data['group']);
                else{
                    $errors = array("code"=>400,"message"=>'group is not valid: bad request');
                }
            } else{
                $fffff = $this->getTabularData($data,$report,$errors);
            }
        }

        // Check for errors
        if(count($errors)){
            $this->response(400, json_encode($errors),$contentType="application/json");
        }

        // Tidying up
        $result = array();
        foreach($fffff['data'] as $d){
            $result[$d[0]] = array();
            for($i = 1; $i < 9; $i+=1){
                $result[$d[0]][$fffff['columns'][$i]] = $d[$i];
            }
        }

        return $result;
    }

    function dateChecker($dateString){
        $date = explode("-",$dateString);
        if(count($date) == 3){
            if(checkdate($date[1],$date[2],$date[0]) && strtotime($dateString) < time()){
               return true;
            }
        }
        
        return false;
    }

    function getTabularData($data,$report,&$error,$group='dept') {

        $event_ids = Event::getIds();
        $event = function ($name) use ($event_ids) {
            return $event_ids[$name];
        };
        $dash_headers = array(__('Opened'),__('Assigned'),__('Overdue'),__('Closed'),__('Reopened'),
                              __('Deleted'),__('Service Time'),__('Response Time'));

        list($start, $stop) = $report->getDateRange();
        $times = ThreadEvent::objects()
            ->constrain(array(
                'thread__entries' => array(
                    'thread__entries__type' => 'R',
                    ),
               ))
            ->constrain(array(
                'thread__events' => array(
                    'thread__events__event_id' => $event('created'),
                    'event_id' => $event('closed'),
                    'annulled' => 0,
                    ),
                ))
            ->filter(array(
                    'timestamp__range' => array($start, $stop, true),
               ))
            ->aggregate(array(
                'ServiceTime' => SqlAggregate::AVG(SqlFunction::timestampdiff(
                  new SqlCode('HOUR'), new SqlField('thread__events__timestamp'), new SqlField('timestamp'))
                ),
                'ResponseTime' => SqlAggregate::AVG(SqlFunction::timestampdiff(
                    new SqlCode('HOUR'),new SqlField('thread__entries__parent__created'), new SqlField('thread__entries__created')
                )),
            ));

            $stats = ThreadEvent::objects()
                ->filter(array(
                        'annulled' => 0,
                        'timestamp__range' => array($start, $stop, true),
                        'thread_type' => 'T',
                   ))
                ->aggregate(array(
                    'Opened' => SqlAggregate::COUNT(
                        SqlCase::N()
                            ->when(new Q(array('event_id' => $event('created'))), 1)
                    ),
                    'Assigned' => SqlAggregate::COUNT(
                        SqlCase::N()
                            ->when(new Q(array('event_id' => $event('assigned'))), 1)
                    ),
                    'Overdue' => SqlAggregate::COUNT(
                        SqlCase::N()
                            ->when(new Q(array('event_id' => $event('overdue'))), 1)
                    ),
                    'Closed' => SqlAggregate::COUNT(
                        SqlCase::N()
                            ->when(new Q(array('event_id' => $event('closed'))), 1)
                    ),
                    'Reopened' => SqlAggregate::COUNT(
                        SqlCase::N()
                            ->when(new Q(array('event_id' => $event('reopened'))), 1)
                    ),
                    'Deleted' => SqlAggregate::COUNT(
                        SqlCase::N()
                            ->when(new Q(array('event_id' => $event('deleted'))), 1)
                    ),
                ));

        switch ($group) {
        case 'dept':
            if(isset($data['dept_id'])){
                $depts = Dept::lookup($data['dept_id']);
                if(!$depts){
                    $error = array("code"=>400,"message"=>'can not find department with given id: bad request');
                    return false;
                }

                $stats = $stats
                ->filter(array('dept_id' => $depts->getId()))
                ->values('dept__id', 'dept__name', 'dept__flags')
                ->distinct('dept__id');
                $times = $times
                ->filter(array('dept_id' => $depts->getId()))
                ->values('dept__id')
                ->distinct('dept__id');
            } else{
                $depts = Dept::getDepartments();
                
                $stats = $stats
                ->filter(array('dept_id__in' => array_keys($depts)))
                ->values('dept__id', 'dept__name', 'dept__flags')
                ->distinct('dept__id');
                
                $times = $times
                ->filter(array('dept_id__in' => array_keys($depts)))
                ->values('dept__id')
                ->distinct('dept__id');
            }

            $headers = array(__('Department'));
            $header = function($row) { return Dept::getLocalNameById($row['dept_id'], $row['dept__name']); };
            $pk = 'dept__id';

            break;
        case 'topic':
            if(isset($data['topic_id'])){
                $topics = Topic::lookup($data['topic_id']);
                if(!$topics){
                    $error = array("code"=>400,"message"=>'can not find topic with given id: bad request');
                    return false;
                }
                $stats = $stats
                ->values('topic_id', 'topic__topic', 'topic__flags')
                ->filter(array('dept_id__in' => array_keys(Dept::getDepartments()), 'topic_id__gt' => 0, 'topic_id' => $topics->getId()))
                ->distinct('topic_id');
            } else{
                $topics = Topic::getHelpTopics();
                $stats = $stats
                ->values('topic_id', 'topic__topic', 'topic__flags')
                ->filter(array('dept_id__in' => array_keys(Dept::getDepartments()), 'topic_id__gt' => 0, 'topic_id__in' => array_keys($topics)))
                ->distinct('topic_id');
            }
            $headers = array(__('Help Topic'));
            $header = function($row) { return Topic::getLocalNameById($row['topic_id'], $row['topic__topic']); };
            $pk = 'topic_id';
            if (empty($topics))
                return array("columns" => array_merge($headers, $dash_headers),
                      "data" => array());

            $times = $times
                ->values('topic_id')
                ->filter(array('topic_id__gt' => 0))
                ->distinct('topic_id');
            break;
        case 'staff':
            if(isset($data['staff_id'])){
                $staff = Staff::lookup($data['staff_id']);
                if(!$staff){
                    $error = array("code"=>400,"message"=>'can not find staff with given id: bad request');
                    return false;
                }

                $stats = $stats
                ->values('staff_id', 'staff__firstname', 'staff__lastname')
                ->filter(array('staff_id' => $staff->getId()))
                ->distinct('staff_id');
            } else{
                $staff = Staff::getStaffMembers();
                $stats = $stats
                ->values('staff_id', 'staff__firstname', 'staff__lastname')
                ->filter(array('staff_id__in' => array_keys($staff)))
                ->distinct('staff_id');
            }
            $headers = array(__('Agent'));
            $header = function($row) { return new AgentsName(array(
                'first' => $row['staff__firstname'], 'last' => $row['staff__lastname'])); };
            $pk = 'staff_id';
            
           
            $times = $times->values('staff_id')->distinct('staff_id');
            $depts = array_keys(Dept::getDepartments());
            //if ($staff->hasPerm(ReportModel::PERM_AGENTS))
            $depts = array_merge($depts, array_keys(Dept::getDepartments()));
            //$Q = Q::any();
            if ($depts)
                $Q= Q::any(array('dept_id__in' => $depts));
            $stats = $stats->filter(array('staff_id__gt'=>0))->filter($Q);
            $times = $times->filter(array('staff_id__gt'=>0))->filter($Q);
            break;
        default:
            # XXX: Die if $group not in $groups
        }

        $timings = array();
        foreach ($times as $T) {
            $timings[$T[$pk]] = $T;
        }
        $rows = array();
        foreach ($stats as $R) {
          if (isset($R['dept__flags'])) {
            if ($R['dept__flags'] & Dept::FLAG_ARCHIVED)
              $status = ' - '.__('Archived');
            elseif ($R['dept__flags'] & Dept::FLAG_ACTIVE)
              $status = '';
            else
              $status = ' - '.__('Disabled');
          }
          if (isset($R['topic__flags'])) {
            if ($R['topic__flags'] & Topic::FLAG_ARCHIVED)
              $status = ' - '.__('Archived');
            elseif ($R['topic__flags'] & Topic::FLAG_ACTIVE)
              $status = '';
            else
              $status = ' - '.__('Disabled');
          }

            $T = $timings[$R[$pk]];
            $rows[] = array($header($R) . $status, $R['Opened'], $R['Assigned'],
                $R['Overdue'], $R['Closed'], $R['Reopened'], $R['Deleted'],
                number_format($T['ServiceTime'], 1),
                number_format($T['ResponseTime'], 1));
        }
        return array("columns" => array_merge($headers, $dash_headers),
                     "data" => $rows);
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
class PipeApiController extends StatsApiController {

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
