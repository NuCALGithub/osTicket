<?php
/*********************************************************************
    http.php

    HTTP controller for the osTicket API

    Jared Hancock
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
// Use sessions — it's important for SSO authentication, which uses
// /api/auth/ext
define('DISABLE_SESSION', false);

require 'api.inc.php';

# Include the main api urls


require_once INCLUDE_DIR."class.dispatcher.php";

$dispatcher = patterns('',
        url_post("^/tickets\.create\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','create')),
        url_get("^/tickets\.get\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','get')),
        url_get("^/tickets\.search\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','ticketSearch')),
        url_get("^/tickets\.department\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','deptTickets')),
        url_get("^/tickets\.have.org\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','ticketHaveOrg')),
        url_get("^/tickets\.organization\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','orgTickets')),
        url_post("^/tickets\.note\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','postNoteTicket')),
        url_post("^/tickets\.thread\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','threadAction')),
        url_post("^/tickets\.reply\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','replyTicket')),
        url_post("^/tickets\.assign\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','assignTicket')),
        url_post("^/tickets\.reopen\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','reopenTicket')),
        url_post("^/tickets\.update\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','update')),
        url_post("^/tickets\.close\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','closeTicket')),
        url_post("^/tickets\.transfer\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','transferTicket')),
        url_delete("^/tickets\.delete\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','deleteTicket')),
        url_get("^/sla\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','getSLA')),
        url_get("^/topic\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','getTopic')),
        url_get("^/staff\.get\.tickets\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','getStaffTickets')),
        url_get("^/staff\.get\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','getStaff')),
        url_get("^/staff\.get\.list\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','getAllStaff')),
        url_get("^/user\.get\.(?P<format>xml|json|email)$", array('api.user.php:UserApiController','getUser')),
        url_post("^/user\.update\.(?P<format>xml|json|email)$", array('api.user.php:UserApiController','updateUser')),
        url_post("^/user\.lock\.(?P<format>xml|json|email)$", array('api.user.php:UserApiController','lockUser')),
        url_post("^/user\.unlock\.(?P<format>xml|json|email)$", array('api.user.php:UserApiController','unlockUser')),
        url_post("^/user\.create\.(?P<format>xml|json|email)$", array('api.user.php:UserApiController','createUser')),
        url_get("^/role\.get\.(?P<format>xml|json|email)$", array('api.role.php:RoleApiController','getRole')),
        url_post("^/role\.create\.(?P<format>xml|json|email)$", array('api.role.php:RoleApiController','createRole')),
        url_post("^/role\.update\.(?P<format>xml|json|email)$", array('api.role.php:RoleApiController','updateRole')),
        url_delete("^/role\.delete\.(?P<format>xml|json|email)$", array('api.role.php:RoleApiController','deleteRole')),
        url_post("^/dept\.create\.(?P<format>xml|json|email)$", array('api.dept.php:DeptApiController','createDept')),
        url_post("^/dept\.update\.(?P<format>xml|json|email)$", array('api.dept.php:DeptApiController','updateDept')),
        url_delete("^/dept\.delete\.(?P<format>xml|json|email)$", array('api.dept.php:DeptApiController','deleteDept')),
        url('^/tasks/', patterns('',
                url_post("^cron$", array('api.cron.php:CronApiController', 'execute'))
         ))
        );

Signal::send('api', $dispatcher);

# Call the respective function
print $dispatcher->resolve($ost->get_path_info());
?>
