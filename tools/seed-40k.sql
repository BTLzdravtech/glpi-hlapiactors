SET @now = NOW();
INSERT INTO glpi_users (id, name, realname, firstname, is_active, entities_id, authtype, date_creation, date_mod)
  SELECT seq, CONCAT('user', seq), CONCAT('Surname', seq), CONCAT('Name', seq), 1, 0, 1, @now, @now FROM seq_1000_to_1599;
INSERT INTO glpi_groups (id, name, completename, entities_id, is_recursive, date_creation, date_mod)
  SELECT seq, CONCAT('Group ', seq), CONCAT('Group ', seq), 0, 1, @now, @now FROM seq_1_to_30;
INSERT INTO glpi_tickets (id, entities_id, name, date, date_mod, users_id_lastupdater, status, users_id_recipient, requesttypes_id, content, urgency, impact, priority, itilcategories_id, type, global_validation, is_deleted, date_creation, actiontime, waiting_duration, close_delay_stat, solve_delay_stat, takeintoaccount_delay_stat)
  SELECT seq, 0, CONCAT('Ticket ', seq), DATE_SUB(@now, INTERVAL seq*40 MINUTE), DATE_SUB(@now, INTERVAL seq*30 MINUTE), 2,
         CASE WHEN seq <= 5400 THEN 1 + (seq % 4) ELSE 5 + (seq % 2) END,
         1000 + (seq % 600), 1, CONCAT('<p>Content of ticket ', seq, ' about printer outage and network issue.</p>'), 3, 3, 3, 1 + (seq % 20), 1 + (seq % 2), 1, 0,
         DATE_SUB(@now, INTERVAL seq*40 MINUTE), 0, 0, 0, 0, 0
  FROM seq_1_to_40000;
INSERT INTO glpi_tickets_users (tickets_id, users_id, type, use_notification) SELECT id, users_id_recipient, 1, 1 FROM glpi_tickets;
INSERT INTO glpi_tickets_users (tickets_id, users_id, type, use_notification) SELECT id, 1000 + (id % 30), 2, 1 FROM glpi_tickets WHERE id % 100 < 85;
INSERT INTO glpi_tickets_users (tickets_id, users_id, type, use_notification) SELECT id, 1100 + (id % 200), 3, 1 FROM glpi_tickets WHERE id % 10 < 3;
INSERT INTO glpi_groups_tickets (tickets_id, groups_id, type) SELECT id, 1 + (id % 30), 2 FROM glpi_tickets WHERE id % 10 < 4;
INSERT INTO glpi_tickets_users (tickets_id, users_id, type, use_notification) SELECT t.seq, 1200 + o.seq, 3, 1 FROM seq_1_to_200 t JOIN seq_1_to_15 o;
INSERT INTO glpi_tickets_users (tickets_id, users_id, type, use_notification) SELECT t.seq, 1000 + a.seq, 2, 1 FROM seq_1_to_200 t JOIN seq_31_to_32 a;
INSERT INTO glpi_groups_tickets (tickets_id, groups_id, type) SELECT t.seq, g.seq, 2 FROM seq_1_to_200 t JOIN seq_25_to_26 g;
-- validations on 3 000 open tickets: 5 approvers (users 1040-1044); 60% waiting, 30% accepted, 10% refused
INSERT INTO glpi_ticketvalidations (entities_id, users_id, tickets_id, itemtype_target, items_id_target, comment_submission, status, submission_date, timeline_position)
  SELECT 0, 1000 + (seq % 600), seq, 'User', 1040 + (seq % 5), 'please approve', CASE WHEN seq % 10 < 6 THEN 2 WHEN seq % 10 < 9 THEN 3 ELSE 4 END, DATE_SUB(@now, INTERVAL seq MINUTE), 1 FROM seq_1_to_3000;
-- 20 000 tasks: technicians 1000-1029, states 0/1/2, planned dates for a third
INSERT INTO glpi_tickettasks (tickets_id, date, users_id, content, is_private, actiontime, begin, end, state, users_id_tech, groups_id_tech, date_mod, date_creation, timeline_position)
  SELECT 1 + (seq % 40000), DATE_SUB(@now, INTERVAL seq*10 MINUTE), 1000 + (seq % 30), CONCAT('Task ', seq), 0, 900, IF(seq % 3 = 0, DATE_ADD(@now, INTERVAL (seq % 14) DAY), NULL), IF(seq % 3 = 0, DATE_ADD(@now, INTERVAL (seq % 14) DAY), NULL), seq % 3, 1000 + (seq % 30), 0, @now, @now, 1 FROM seq_1_to_20000;
-- 60 000 followups by 600 authors
INSERT INTO glpi_itilfollowups (itemtype, items_id, date, users_id, content, is_private, requesttypes_id, date_mod, date_creation, timeline_position)
  SELECT 'Ticket', 1 + (seq % 40000), DATE_SUB(@now, INTERVAL seq*5 MINUTE), 1000 + (seq % 600), CONCAT('Followup ', seq), 0, 1, @now, @now, 1 FROM seq_1_to_60000;
-- group membership: every user in 1-2 groups; user 1005 in groups 5 and 6, manager of 5
INSERT INTO glpi_groups_users (users_id, groups_id, is_manager, is_dynamic) SELECT seq, 1 + (seq % 30), IF(seq % 30 = 5, 1, 0), 0 FROM seq_1000_to_1599;
INSERT INTO glpi_groups_users (users_id, groups_id, is_manager, is_dynamic) SELECT seq, 1 + ((seq + 1) % 30), 0, 0 FROM seq_1000_to_1599 WHERE seq % 2 = 1;
-- pending reasons on 300 open tickets
INSERT INTO glpi_pendingreasons (id, name, entities_id, is_recursive, followup_frequency, followups_before_resolution) VALUES (1,'Waiting for user',0,1,0,0),(2,'Waiting for vendor',0,1,0,0),(3,'Waiting for parts',0,1,0,0);
INSERT INTO glpi_pendingreasons_items (pendingreasons_id, items_id, itemtype, followup_frequency, followups_before_resolution, bump_count, previous_status) SELECT 1 + (seq % 3), seq, 'Ticket', 0, 0, 0, 1 FROM seq_1_to_300;
SELECT (SELECT COUNT(*) FROM glpi_tickets) tickets, (SELECT COUNT(*) FROM glpi_tickets_users) actors, (SELECT COUNT(*) FROM glpi_ticketvalidations) validations, (SELECT COUNT(*) FROM glpi_tickettasks) tasks, (SELECT COUNT(*) FROM glpi_itilfollowups) followups, (SELECT COUNT(*) FROM glpi_groups_users) memberships, (SELECT COUNT(*) FROM glpi_pendingreasons_items) pending,
       (SELECT COUNT(*) FROM glpi_tickets_users tu LEFT JOIN glpi_users u ON u.id=tu.users_id WHERE u.id IS NULL) dangling_users;
