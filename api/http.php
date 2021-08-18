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
        url_get("^/sla\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','getSLA')),
        url_get("^/topic\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','getTopic')),
        url_get("^/staff\.get\.tickets\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','getStaffTickets')),
        url_get("^/staff\.get\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','getStaff')),
        url_get("^/staff\.get\.list\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','getAllStaff')),
        url_post("^/tickets\.close\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','closeTicket')),
        url_post("^/tickets\.transfer\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','transferTicket')),
        url_post("^/tickets\.note\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','postNoteTicket')),
        url_post("^/tickets\.thread\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','threadAction')),
        url_post("^/tickets\.reply\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','replyTicket')),
        url_post("^/tickets\.assign\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','assignTicket')),
        url_post("^/tickets\.reopen\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','reopenTicket')),
        url_post("^/tickets\.update\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','update')),
        url_delete("^/tickets\.delete\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','deleteTicket')),
        url('^/tasks/', patterns('',
                url_post("^cron$", array('api.cron.php:CronApiController', 'execute'))
         ))
        );

Signal::send('api', $dispatcher);

# Call the respective function
print $dispatcher->resolve($ost->get_path_info());
?>
