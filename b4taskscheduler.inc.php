<?php
/**
 * B4Com (C) 2022
 * Andrey Grey megagramm@gmail.com
 * Yuri Morin yurkiywork@gmail.com
 *
 * Менеджер задач. Запускает задачи по расписанию в несколько потоков.
 */

class B4TaskScheduler
{
    // Todo: Добавить режим debug. Расширенные логи, в обычном режиме без них

    // Todo: Сделать в БД заглушки client_id

    // Todo: Переделать методы с учетом привиллегий пользователей

    // Todo: Делать проверки задач, которые выполнялись по крону и которые больше никогда по этому крону не будут выполненны. Например цикличные задачи на 2020-й год

    // Todo: Делать проверку задач по датам старта, будут ли они когда-нибудь выполненны?

    protected $task_count_in_stack = 4; // кол-во одновременно работающих процессов
    protected $process_runtime = 5000; //Продолжительность работы процесса
    protected $process_runing_method = 'proc_open'; // Метод работы с процессами
    protected $debug_mod = false; // Если True, будет больше сопроводительной информации в выводе и логах

    public $errorlog_path = "b4_error.log"; // Текстовый лог ошибок

    protected $path_to_json_files = __DIR__ . '/tasks_jsons/'; // Путь к файлам с задачами для загрузки в БД

    protected $task_array = []; // массив всех задач
    protected $task_array_empty = false; //Если тру, не позволяем работать с массивом задач
    protected $task_stack = []; // массив задач в работе, ограничен $task_count_in_stack
    protected $tasks_completed = []; // массив выполненных задач

    
    // Подключение к БД
    protected $conn; // Активное подключение

    private $host = 'localhost';
    private $dbname = 'b4com';
    private $username = 'b4com';
    private $password = 'qwerty';

    private $SQL_status_all = "
            'ready',".                 // Начальный статус
            "'started',".              // Статус означает что задача запущена
            "'canceled',".             // Отмененный. Пользователь отменил задачу.
            "'declined',".             // Отклоненный статус. Программа отклонила выполнение задачи
            "'pause',".                // Задача поставлена на паузу
            "'inprogress',".           // Задача в работе. Цизадача которая выполняется в цикле.
            "'complete'";              // Законеченная задача
    private $SQL_status_work = "'ready','started','inprogress'"; // рабочие статусы
    private $SQL_status_finish = "'canceled','declined','complete'"; //законченные статусы

    private $SQL_running_methods = "
            'now',".                    //  Немедленно
            "'run_on',".                //  В опрелеленную дату и время
            "'cron'";                   //  По крону

    //подготовка proc_open
    public $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', 'b4_error-output.txt', "a"], // путь к лог-файлу ошибок proc_open
    ];

    /**
     * Функция доступа переопределяется в дочернем классе
     *
     * @return void
     */
    public function permission()
    {
    }
    /**
     * Пишет сообщение в лог
     *
     *  TODO: Добавить аргументы в функцию с возможностью логировать в БД
     * @param string $text
     * @return void
     */
    protected function log_error(string $text)
    {
        $handle = fopen($this->errorlog_path, "a");
        $line = date("Y-m-d H:i:s") . ' | ' . $text . PHP_EOL;
        fwrite($handle, $line);
        fclose($handle);
    }

    /**
     * Вывод сообщения
     *
     * @param string $status
     * @param string $message
     * @return void
     */
    public function send_message(string $status, string $message)
    {
        $output = array(
            'status' => $status,
            'message' => $message,
        );

        echo json_encode($output) . PHP_EOL;
    }


    /**
     * Загружаем данные из json
     * устаревший и ненужный метод
     *
     *
     * @param string $file
     * @return array
     */
    public function load_from_json(string $file)
    {
        if (!file_exists($file)) {
            $this->send_message('error', 'File not exists');
            $this->task_array_empty = true;
            exit();
        }

        $file_data = trim(file_get_contents($file));
        if (empty($file_data)) {
            $this->send_message('error', 'File is empty');
            $this->task_array_empty = true;
            exit();
        }

        $array = json_decode($file_data, true);
        if (!is_array($array)) {
            $this->send_message('error', 'JSON doesn`t decoded');
            $this->task_array_empty = true;
            exit();
        }

        $this->task_array = $array;
    }

    public function DB_connect()
    {
        try {
            $this->conn = new PDO("mysql:host=$this->host;dbname=$this->dbname", $this->username, $this->password);
            // echo "Connected to $dbname at $host successfully.";
            return $this->conn;
        } catch (PDOException $pe) {
            die("Could not connect to the database $this->dbname :" . $pe->getMessage());
        }
    }
    
    /**
     * Проверяет директорию на наличие json файлов. 
     * Если есть, загружвет их в БД, перед запуском задач
     *  
     */
    public function DB_load_tasks_from_path()
    {
        // Todo: Переделать добавление из json на добавление задач из определенной папки всех файлов json. Если в папке есть файл, добавляются задачи из него в БД. Исходный файл удаляется. Ошибочные файлы пишутся в лог.
        //TODO: Сделать
        // Берем директорию в которой должны лежать файлы
        // проверяем есть ли там файлы json. Если есть, то берем данные и заливаем в БД
        // удаляем файл/ перемещаем в подпапку loaded
    }

    /**
     * Добавляет задачу
     *
     * @return void
     */
    public function TASKS_add_tasks($id)
    {   
        // Todo: Сделать метод добавления задачи в бд
        //TODO: Сделать
        $sql = "INSERT INTO `taskscheduler` (`user`,`name`, `command`, `running_type`,) VALUES (?,?,?)";
        return $this->conn->prepare($sql)->execute(['ready', $id]);
    }

    /**
     * Удаляет задачи
     *
     * @return void
     */
    public function TASKS_delete_tasks($id)
    {   
        // Todo: Метод удаления задачи из бд (только те что не выполнены)
        $sql = "DELETE FROM `taskscheduler` WHERE id=?";
        return $this->conn->prepare($sql)->execute([$id]);
    }

    /**
     * Клонирование существующей задачи.
     * Статус при этом ставится в паузу
     *
     * @return void
     */
    public function TASKS_clone_task($id)
    {   
        // Todo: Клонировать задачу, если это не задача по крону
        $sql = 
        "INSERT INTO `taskscheduler` (`disabled`,`block`,`god_priority`,`priority`,`user`,`command`,`run_on`,`cron`,`status`)
        SELECT `disabled`,`block`,`god_priority`,`priority`,`user`,`command`,`run_on`,`cron`,'pause' as `status` FROM `taskscheduler` WHERE `id` = ?;
        ";
        $this->conn->prepare($sql)->execute([$id]);

    }
    /**
     * Начальный статус. Используется, например, когда нужно вернуть задачу из паузы
     */
    public function TASKS_set_status_ready($id)
    {
        $sql = "UPDATE taskscheduler SET status=? WHERE id=?";
        $this->conn->prepare($sql)->execute(['ready', $id]);
    }

    /**
     * Когдла программа начинает работать назначает статус после ready
     *
     * @return void
     */
    public function TASKS_set_status_started($id)
    {
        $sql = "UPDATE taskscheduler SET status=? WHERE id=?";
        $this->conn->prepare($sql)->execute(['started', $id]);
    }
    
    public function TASKS_set_status_canceled($id)
    {
        $sql = "UPDATE taskscheduler SET status=? WHERE id=?";
        $this->conn->prepare($sql)->execute(['canceled', $id]);
    }

    /**
     * Пользователь может остановить выполнение своих задач
     *
     */
    public function TASKS_set_status_pause($id)
    {   
        $sql = "UPDATE taskscheduler SET `status`=? WHERE id=? AND `status` not IN ($this->SQL_status_finish)";
        return $this->conn->prepare($sql)->execute(['pause', $id]);
    }

    public function TASKS_set_status_inprogress($id)
    {
        $sql = "UPDATE taskscheduler SET `status`=? WHERE id=?";
        return $this->conn->prepare($sql)->execute(['inprogress', $id]);
    }

    public function TASKS_set_status_complete($id)
    {
        $sql = "UPDATE taskscheduler SET `status`=? WHERE id=?";
        return $this->conn->prepare($sql)->execute(['complete', $id]);
    }

    /**
     * В случае ошибки в задаче или в тригере запуска, программа ставит этот статус
     */
    public function TASKS_set_status_declined($id)
    {
        $sql = "UPDATE taskscheduler SET `status`=? WHERE id=?";
        return $this->conn->prepare($sql)->execute(['declined', $id]);
    }


    

    public function TASKS_disable_switch($id)
    {
        $sql = "UPDATE taskscheduler SET `disabled`=IF(`disabled`>0,0,1) WHERE id=? AND `status` NOT IN ($this->SQL_status_finish)";
        return $this->conn->prepare($sql)->execute([$id]);
    }

    public function TASKS_block_switch($id)
    {   
        // Todo: Заблокировать задачу в БД(одну (ид записи), список(ид записей), все(all)) . Блокируются только невыполненные
        $sql = "UPDATE taskscheduler SET `block`=IF(`block`>0,0,1) WHERE id=?";
        return $res = $this->conn->prepare($sql)->execute([$id]);
    }


    /**
     * Загружает задачи из БД
     *
     * @return void
     */
    public function DB_get_tasks()
    {
        $db_array = [];

        $conn = $this->DB_connect();

        $res = $conn->query("

        SELECT `id`, `command`, `cron`, `run_on`,`status`
        FROM `taskscheduler`
        WHERE
            disabled = 0
            AND block = 0
            AND status  IN ({$this->SQL_status_work});

        ");
        
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $db_array[] = $row;
        }
        if (empty($db_array)) {
            $this->task_array_empty = true;
        }

        $this->task_array = $db_array;
    }

    /**
     * Создаём стек для работы
     *
     * @return void
     */
    public function make_task_stack()
    {
        //берём в стек первые задачи
        $this->task_stack = array_slice($this->task_array, 0, $this->task_count_in_stack, true);

        //отсекаем у исходного массива взятые элементы
        array_splice($this->task_array, 0, $this->task_count_in_stack);
    }

    /**
     * Создаем процесс
     *
     * @param [type] $task_data
     * @param [type] $task_stack_id
     * @return void
     */
    public function make_proc(&$task_data, &$task_stack_id)
    {
        echo 'Stack id is ' . $task_stack_id . ', task_id is ' . $task_data['id'] . ' command: ' . $task_data['command'] . PHP_EOL;
        // print_r ($task_data);

        $task_data['process'] = proc_open($task_data['command'], $this->descriptorspec, $task_data['pipes']);
        $task_data['pipe1_data'] = '';

        if (!is_resource($task_data['process'])) {
            $this->send_message("error", "process is missing. Task {$task_data['id']} skipped");
            unset($this->task_stack[$task_stack_id]);
        }

        stream_set_blocking($task_data['pipes'][1], false);

        $task_data['status'] = proc_get_status($task_data['process']);
    }

    /**
     * Добавляет новую задачу в стек
     *
     * @return void
     */
    protected function add_task_in_stack()
    {

        //проверить не пустой ли исходный массив
        if (empty($this->task_array)) {
            return;
        }

        // добавить элементы в стек
        // берём в стек первую задачу из task_array
        $task = array_slice($this->task_array, 0, 1, true);

        // отсекаем у исходного массива взятый элемент
        array_splice($this->task_array, 0, 1);

        // добавляем в стек задачу
        $this->task_stack[] = $task[0];

        // правильно берем последний элементы стека для создания процесса (метод до php 7.3 , далее array_key_last)
        $last_id_of_task_stack = array_keys($this->task_stack)[count($this->task_stack) - 1];

        // создать процесс
        $this->make_proc($this->task_stack[$last_id_of_task_stack], $last_id_of_task_stack);
    }

    /**
     * Проверяет массив перед запуском. Правильная ли структура.
     * Неподходящие элементы убирает.
     *
     * TODO: Должен писать в лог отдельно от метода send_message
     * TODO: Должен писать в лог БД что задача не пошла в работу
     * @return void
     */
    public function check_task_array()
    {
        if (empty($this->task_array)) {
            $this->send_message('error', 'Task array is empty');
            exit();
        }

        foreach ($this->task_array as $task_array_id => &$task_data) {

            // print_r($task_data);

            if (!isset($task_data['id']) or empty($task_data['id'])) {
                $this->send_message('error', "Array index {$task_array_id}: field 'id' is missing or empty");
                unset($this->task_array[$task_array_id]);
                continue;
            }

            if (!array_key_exists('command', $task_data) or empty($task_data['command'])) {
                $this->send_message('error', "Task {$task_data['id']}: 'command' field is missing or empty");
                unset($this->task_array[$task_array_id]);
                continue;
            }

            if (!array_key_exists('run_on', $task_data)) {
                $this->send_message('error', "Task {$task_data['id']}: 'run_on' field is missing");
                unset($this->task_array[$task_array_id]);
                continue;
            }

            if (!array_key_exists('cron', $task_data)) {
                $this->send_message('error', "Task {$task_data['id']}: 'cron' field is missing");
                unset($this->task_array[$task_array_id]);
                continue;
            }

            if (!array_key_exists('status', $task_data) or empty($task_data['status'])) {
                $this->send_message('error', "Task {$task_data['id']}: 'status' field is missing or empty");
                unset($this->task_array[$task_array_id]);
                continue;
            }

            // print_r($this->task_array);
            $now_datetime = date("Y-m-d H:i:00");
            if (empty($task_data['cron']) and empty($task_data['run_on'])) {
                // Todo: Выполняется разовая задача
            } elseif (empty($task_data['cron']) and !empty($task_data['run_on'])) {
                // Todo: Правильнее сделать выборку из БД, если подходящие услвоия
                if($task_data['run_on'] != $now_datetime){
                    $this->send_message('error', "Task {$task_data['id']}: 'wrong date.'");
                    unset($this->task_array[$task_array_id]);
                }
            } elseif (empty($task_data['run_on']) and !empty($task_data['cron'])) {
                // Todo: Выполняется задача по крону, если совпадает с условиями
                
            } else {
                // Todo: Записать в БД прочие ошибки и изменить статус задачи на canceled
            }

            // print_r($task_data);

            // die();
        }
    }

    /**
     * Запускает процессы из стека задач в работу, закрывает выполненные и добавляет новые
     *
     * @return void
     */
    public function run_procs()
    {
        if ($this->task_array_empty == true) {
            $this->send_message('error', 'run_proc failed to start');
            exit();
        }
        $this->check_task_array();

        $this->make_task_stack();

        // запускаем первые процессы стека
        if (isset($this->task_stack) && !empty($this->task_stack)) {
            foreach ($this->task_stack as $task_stack_id => &$task_data) {
                $this->make_proc($task_data, $task_stack_id);
            }
        }

        if (isset($this->task_stack) && !empty($this->task_stack)) {
            while (!empty($this->task_stack)) {
                foreach ($this->task_stack as $task_stack_id => &$task_data) {

                    if (!feof($task_data['pipes'][1])) {
                        $task_data['pipe1_data'] .= fgets($task_data['pipes'][1]);
                        echo fgets($task_data['pipes'][1]);
                    } else {
                        fclose($task_data['pipes'][0]);
                        echo 'Stack id(' . $task_stack_id . ') is closed, task_id is ' . $task_data['id'] . PHP_EOL;
                        fclose($task_data['pipes'][1]);
                        proc_close($task_data['process']);
                        unset($this->task_stack[$task_stack_id]);

                        //добавить следующую задачу
                        $this->add_task_in_stack();
                    }
                }
            }
        }
    }

    /**
     * Назначает принудительно метод обработки процессов.
     *
     * @param string $method_type
     * @return void
     */
    public function set_process_running_method(string $method = 'proc_open')
    {
        if (in_array($method, ['proc_open', 'ptreads', 'parallel'])) {
            $this->process_runing_method = $method;
        } else {
            $this->process_runing_method = 'proc_open';
        }
    }

    /**
     * Определяет каким методом работать с процессами:
     *     proc_open
     *     ptreads         нужен php с zts
     *     parallel        нужен php с zts
     *
     * @return void
     */
    public function get_process_runing_method()
    {
        if (PHP_ZTS == 0) {
            $this->select_process_runing_method = 'proc_open';
        } elseif (PHP_ZTS == 1 && ((PHP_MAJOR_VERSION == 7 && PHP_MINOR_VERSION >= 4) || PHP_MAJOR_VERSION > 7)) {
            $this->select_process_runing_method = 'parallel';
        } else {
            $this->select_process_runing_method = 'ptreads';
        }
        echo $this->select_process_runing_method . PHP_EOL;

        if (PHP_ZTS == 0) {
            $this->select_process_runing_method = 'proc_open';
        }
        else{
            // Todo: Добавить проверку на поиск модулей pthreads и parallel в директориях
            if (version_compare(PHP_VERSION, '7.2.0') >= 0) {
                $this->select_process_runing_method = 'parallel';
            }
            else{
                $this->select_process_runing_method = 'ptreads';
            }
        }
        echo $this->select_process_runing_method . PHP_EOL;
    }
}

/**
 *  Тестирование скрипта
 *  Включается, если запустить сам скрипт, без вложения по include, require
 */
if ($argv && $argv[0] && realpath($argv[0]) === __FILE__) {
//start testing

    // Todo: Перед запуском скрипта, устанавливать задачи в БД в статус ready

    class Tsk extends B4TaskScheduler
    {
    }

    $file = 'commands.json';
    $job = new Tsk;
    // $job = new B4TaskScheduler;
    $job->DB_connect();

    // выясняем метод работы
    $job->get_process_runing_method();
    // die();
    //загружаем задачи из json
    // $job->load_from_json($file);

        // $job->TASKS_set_status_pause(3);
        // $job->TASKS_disable_switch(3);
        // $job->TASKS_clone_task(10);
        // $job->TASKS_block_switch(10);
        $job->TASKS_delete_tasks(12);
    // загружаем задачи из БД
    // $job->DB_get_tasks();

    // запускаем на исполение
    // $job->run_procs();

} // end testing
