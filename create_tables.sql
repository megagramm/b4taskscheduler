CREATE TABLE `taskscheduler` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `disabled` tinyint(1) unsigned DEFAULT 0 COMMENT 'Отключение задач пользователем. Почти аналог pause',
  `block` tinyint(1) unsigned DEFAULT 0 COMMENT 'Блокировка выполнения задачи администраторм,  не путать с pause-статус. Возможность оптом приостановить все задачи  пользователя, например',
  `god_priority` tinyint(1) unsigned DEFAULT 0 COMMENT 'Наивысший приоритет, если true. Выполняется над всеми остальными задачами.',
  `priority` tinyint(3) unsigned DEFAULT 1 COMMENT 'Приоритет 1-255',
  `client_id` int(11) DEFAULT NULL COMMENT 'NULL - так как может запускаться без пользователя',
  `user_id` int(11) DEFAULT NULL COMMENT 'NULL - так как может запускаться без пользователя',
  `created` datetime DEFAULT current_timestamp() COMMENT 'Время создания',
  `name` varchar(255) DEFAULT '=NONAME=' COMMENT 'Название задачи',
  `command` varchar(255) NOT NULL DEFAULT '' COMMENT 'Строка периодичности выполнения. Если пустая, то разовое выполнение в любой  в первый же доступный момент',
  `status` enum('ready','started','canceled','declined','pause','inprogress','complete') NOT NULL DEFAULT 'ready' COMMENT 'Статус выполнения скрипта. В случае, если скрипт с статусом inprogress, значит либо это цикличная задача, либо зависшая',
  `running_type` enum('now','run_on','cron') NOT NULL DEFAULT 'now' COMMENT 'Тип запуска',
  `run_on` datetime DEFAULT NULL COMMENT 'Дата и время старта. Если указано, ипользовать его',
  `cron` varchar(255) DEFAULT NULL COMMENT 'Время и даты запуска в crontab формате',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4;

CREATE TABLE `taskscheduler_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `taskscheduler_id` int(11) unsigned DEFAULT NULL,
  `user_id` int(11) unsigned DEFAULT NULL,
  `result_status` tinyint(1) DEFAULT NULL COMMENT 'Статус выполнения скрипта. Линукс возвращает 0 или 1????',
  `result_text` text DEFAULT NULL COMMENT 'Дескриптион выполнения скрипта. Результат вывода, итп',
  `started_at` datetime DEFAULT NULL COMMENT 'Время начала выполнения скрипта. Созадния записи',
  `stoped_at` datetime DEFAULT NULL COMMENT 'Время окончания',
  `microtime` varchar(255) DEFAULT NULL COMMENT 'Значения старта, окончания и длительности в микросекундах, разделены пробелами',
  PRIMARY KEY (`id`),
  KEY `taskscheduler_id` (`taskscheduler_id`)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4;