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

    // Todo: Понять и настроить взаимодействие с ACL

    // Todo: Переделать методы с учетом привиллегий пользователей

    // Todo: Делать проверки задач, которые выполнялись по крону и которые больше никогда по этому крону не будут выполненны. Например цикличные задачи на 2020-й год

    // Todo: Делать проверку задач по датам старта, будут ли они когда-нибудь выполненны?

    protected $task_count_in_stack = 4; // кол-во одновременно работающих процессов
    protected $process_runtime = 5000; //Продолжительность работы процесса
    protected $process_runing_method = 'proc_open'; // Метод работы с процессами

    // Todo: Добавить режим debug. Расширенные логи, в обычном режиме без них
    public $debug_mod = false; // Если True, будет больше сопроводительной информации в выводе и логах

    public $errorlog_path = "b4_error.log"; // Текстовый лог ошибок

    protected $path_to_json_files = __DIR__ . '/tasks_json/'; // Путь к файлам с задачами для загрузки в БД

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

    public $SQL_status_all = "".
            "'ready',".                 // Начальный статус
            "'started',".              // Статус означает что задача запущена
            "'canceled',".             // Отмененный. Пользователь отменил задачу.
            "'declined',".             // Отклоненный статус. Программа отклонила выполнение задачи
            "'pause',".                // Задача поставлена на паузу
            "'inprogress',".           // Задача в работе. Цизадача которая выполняется в цикле.
            "'complete'";              // Законеченная задача
    public $SQL_status_work = "'ready','started','inprogress'"; // рабочие статусы
    public $SQL_status_finish = "'canceled','declined','complete'"; //законченные статусы

    private $SQL_running_methods = "".
            "'now',".                    //  Немедленно
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
     * Пишет сообщение в лог-файл
     *
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
    
    public function DB_connect(string $host = '', string $dbname = '', string $username = '', string $password = '')
    {
        if(empty($host)|| empty($dbname)||empty($username)||empty($password)){
            $host = $this->host;
            $dbname = $this->dbname;
            $username = $this->username;
            $password = $this->password;
        }
        
        try {
            $this->conn = new PDO("mysql:host={$host};dbname={$dbname}", $username, $password);
            // echo "Connected to $dbname at $host successfully.";
            return $this->conn;
        } catch (PDOException $pe) {
            $msg = "Could not connect to the database $this->dbname :" . $pe->getMessage();
            $this->log_error('error',$msg);
            die($msg);
        }
    }
    
    /**
     * Запись лога в БД. 
     * 
     * При невозможности писать в БД, пишет в файл $errorlog_path
     * Метод универсален, сделан чтобы можно ыло писать не только лог по задачам, но и просто фиксировать лог каких-то действий
     * 
     * По этой причине таблица taskscheduler не имеет внешней связи с tasckscheduler_log, а также проверяюются элементы массива msg и создаются пустые в случае отсутствия.
     *
     * @param array $msg
     * @return void
     */
    public function DB_log(array $msg){

        if(!array_key_exists('taskscheduler_id', $msg))$msg['taskscheduler_id']=NULL;
        if(!array_key_exists('result_status', $msg))$msg['result_status']=NULL;
        if(!array_key_exists('result_text', $msg))$msg['result_text']=NULL;
        if(!array_key_exists('started_at', $msg))$msg['started_at']=NULL;
        if(!array_key_exists('stoped_at', $msg))$msg['stoped_at']=NULL;
        if(!array_key_exists('microtime', $msg))$msg['microtime']=NULL;

        $sql = "INSERT INTO `taskscheduler_log` 
        (`taskscheduler_id`,`result_status`, `result_text`, `started_at`,`stoped_at`,`microtime`)
        VALUES (?,?,?,?,?,?)";
        $tsk = [$msg['taskscheduler_id'], $msg['result_status'], $msg['result_text'], $msg['started_at'], $msg['stoped_at'], $msg['microtime']];

        $res = $this->conn->prepare($sql)->execute($tsk);

        // Если по какой-то причине удается записать лог в БД, пишим в файл.
        if ($res != True){
            $log_error_message = "Не удалось записать в БД: taskscheduler_id={$msg['taskscheduler_id']}, result_status={$msg['result_status']}, result_text={$msg['result_text']} started_at={$msg['started_at']}, stoped_at={$msg['stoped_at']}, microtime={$msg['microtime']}";
            $this->log_error($log_error_message);
        }
        return $res;
    }
    
    /**
     * Проверяет директорию на наличие json файлов. 
     * Если есть, загружвет их в БД, перед запуском задач
     * 
     * Файлы импортированные, перемещаем в папку added
     * Файлы, чья структура не подходит перемещаем в папку missed
     * 
     */
    public function DB_load_tasks_from_path()
    {
        // Todo: Переделать добавление из json на добавление задач из определенной папки всех файлов json. Если в папке есть файл, добавляются задачи из него в БД. Исходный файл удаляется. Ошибочные файлы пишутся в лог.
        //TODO: Сделать
        // Берем директорию в которой должны лежать файлы
        // проверяем есть ли там файлы json. Если есть, то берем данные и заливаем в БД
        // удаляем файл / перемещаем в подпапку loaded
        //если файл ны относятся к json, перемещаем их в 
        $f = scandir($this->path_to_json_files);
        foreach($f as $id=>&$file){
            if (in_array($file,['.','..'])) {
                unset($f[$id]);
                continue;
            }
            if(!preg_match('/\.json$/',$file)){
                unset($f[$id]);
                continue;
            }
        }
        print_r($f);

        foreach($f as &$file){
            // Проверяем структура файла

            $file_data = trim(file_get_contents($this->path_to_json_files.$file));
            if (empty($file_data)) {
                $this->send_message('error', 'File is empty');
                //переместить в missed
                rename($this->path_to_json_files.$file,$this->path_to_json_files.'missed/'.date("Y-m-d_H:i:s_").$file);
                continue;
            }

            $array = json_decode($file_data, true);
            if (!is_array($array)) {
                $this->send_message('error', 'JSON doesn`t decoded');
                rename($this->path_to_json_files.$file,$this->path_to_json_files.'missed/'.date("Y-m-d_H:i:s_").$file);
                continue;
            }

            

            // if (!array_key_exists('command', $task_data) or empty($task_data['command'])) {
            //     $text = "Task {$task_data['id']}: 'command' field is missing or empty";
            //     $this->send_message('error', $text);
            //     $this->DB_log(['result_text'=>$text,'started_at'=>date("Y-m-d H:i:s")]);
            //     unset($this->task_array[$task_array_id]);
            //     continue;
            // }


        }

    }

    /**
     * Метод для тестов
     * 
     * Ставит задачи с именем test в статус ready
     *
     * @return void
     */
    public function TEST_TASKS_set_status_ready()
    {   
        $sql = "UPDATE `taskscheduler` SET `status`='ready' WHERE `name`='test'";
        return $this->conn->prepare($sql)->execute();
    }

    /**
     * Добавляет задачу
     * 
     * @return void
     */
    public function TASKS_add_tasks(array $task)
    {   
        // $this->conn->beginTransaction();
        $sql = "INSERT INTO `taskscheduler` (`user`,`name`, `command`, `running_type`,`run_on`,`cron`)
        VALUES (?,?,?,?,?,?)";
        $tsk = [$task['user'], $task['name'], $task['command'], $task['running_type'], $task['run_on'], $task['cron']];
        $res = $this->conn->prepare($sql)->execute($tsk);
        // $this->conn->commit();
        if($res == True){
            $msg=[
                'taskscheduler_id'=>$this->conn->lastInsertId(),
                'result_text'=>'Создана задача',
                'started_at'=>date("Y-m-d H:i:s")
            ];
            $this->DB_log($msg);
        }
        return $res;

    }

    /**
     * Удаляет задачи
     *
     * @return void
     */
    public function TASKS_delete_tasks($id)
    {   
        try{
            $sql = "DELETE FROM `taskscheduler` WHERE id=?";
            $res = $this->conn->prepare($sql)->execute([$id]);
            if($res == True){
                $sql = "DELETE FROM `taskscheduler_log` WHERE `taskscheduler_id`=?";
                $this->conn->prepare($sql)->execute([$id]);
            }
            return $res;

        } catch (Exception $e) {
            $msg=[
                'taskscheduler_id'=>$id,
                'result_text'=>'Не удалось удалить задачу',
                'started_at'=>date("Y-m-d H:i:s")
            ];
            $this->DB_log($msg);
        }
    }

    /**
     * Клонирование существующей задачи.
     * Статус при этом ставится в паузу
     *
     * @return void
     */
    public function TASKS_clone_task($id)
    {   
        $sql = 
        "INSERT INTO `taskscheduler` (`disabled`,`block`,`god_priority`,`priority`,`user`,`name`,`command`,`run_on`,`cron`,`status`)
        SELECT `disabled`,`block`,`god_priority`,`priority`,`user`,`name`,`command`,`run_on`,`cron`,'pause' as `status` FROM `taskscheduler` WHERE `id` = ?;
        ";
        $res =  $this->conn->prepare($sql)->execute([$id]);
        if($res == True){
            $msg=[
                'taskscheduler_id'=>$this->conn->lastInsertId(),
                'result_text'=>"Создана задача клонированием задачи {$id}",
                'started_at'=>date("Y-m-d H:i:s")
            ];
            $this->DB_log($msg);
        }
        return $res;

    }

    /**
     * Просто переводит строку $this->SQL_status_all в массив
     *
     * @param string $string
     * @return array
     */
    public function status_string_to_array(string $string){
        $arr = explode(',',$string);
        foreach ($arr as &$value){
            $value = trim($value,"'");
        }
        return $arr;
    }
    /**
     * Метод назначения статусов
     * 
     * Не используется, пока ну будут понятны все условия назначения статусов
     *
     * @param string $status
     * @param integer $id
     * @return void
     */
    public function TASKS_set_status(string $status,int $id)
    {   
        // Todo: Переделать, чтобы использовался массив из $this->SQL_status_all
        // $arr = $this->status_string_to_array($this->SQL_status_all);
        if (!in_array($status, $this->status_string_to_array($this->SQL_status_all))) die('wrong status');
        // if(!in_array($status,['ready','started','canceled','declined','pause','inprogress','complete'])) die('wrong status');
        try {
            $sql = "UPDATE `taskscheduler` SET `status`=? WHERE `id`=?";
            $res = $this->conn->prepare($sql)->execute([$status, $id]);
            
            if($res == True){
                $msg=[
                    'taskscheduler_id'=>$id,
                    'result_text'=>"Установлен статус {$status}",
                    'started_at'=>date("Y-m-d H:i:s")
                ];
            }
            elseif($res == False){
                $msg=[
                    'taskscheduler_id'=>$id,
                    'result_text'=>"Не удалось поставить статус {$status}",
                    'started_at'=>date("Y-m-d H:i:s")
                ];
            }
            $this->DB_log($msg);
            
            return $res;
        } catch (PDOException $e) {
        }
        
    }

    /**
     * Начальный статус. Используется, например, когда нужно вернуть задачу из паузы
     */
    public function TASKS_set_status_ready($id)
    {
        $sql = "UPDATE `taskscheduler` SET `status`=? WHERE `id`=?";
        $res = $this->conn->prepare($sql)->execute(['ready', $id]);

        if($res == True){
            $msg=[
                'taskscheduler_id'=>$id,
                'result_text'=>'Установлен статус ready',
                'started_at'=>date("Y-m-d H:i:s")
            ];
            $this->DB_log($msg);
        }
        return $res;
    }

    /**
     * Когдла программа начинает работать назначает статус после ready
     *
     * @return void
     */
    public function TASKS_set_status_started($id)
    {
        $sql = "UPDATE taskscheduler SET status=? WHERE id=?";
        return $this->conn->prepare($sql)->execute(['started', $id]);
    }
    
    public function TASKS_set_status_canceled($id)
    {
        $sql = "UPDATE taskscheduler SET `status`=? WHERE id=?";
        return $this->conn->prepare($sql)->execute(['canceled', $id]);
    }

    //! Внедри в общий метод назначения
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
        $res = $this->conn->prepare($sql)->execute([$id]);

        if($res == True){
            $msg=[
                'taskscheduler_id'=>$id,
                'result_text'=>"Задача включена/выключена",
                'started_at'=>date("Y-m-d H:i:s")
            ];
        }
        elseif($res == False){
            $msg=[
                'taskscheduler_id'=>$id,
                'result_text'=>"Не удалось использовать переключатель включено/выключено",
                'started_at'=>date("Y-m-d H:i:s")
            ];
        }
        $this->DB_log($msg);
        
        return $res;
    }

    public function TASKS_block_switch($id)
    {   
        // Todo: Заблокировать задачу в БД(одну (ид записи), список(ид записей), все(all)) . Блокируются только невыполненные
        $sql = "UPDATE taskscheduler SET `block`=IF(`block`>0,0,1) WHERE id=?";
        $res = $this->conn->prepare($sql)->execute([$id]);

        if($res == True){
            $msg=[
                'taskscheduler_id'=>$id,
                'result_text'=>"Задача заблокирована/разблокирована",
                'started_at'=>date("Y-m-d H:i:s")
            ];
        }
        elseif($res == False){
            $msg=[
                'taskscheduler_id'=>$id,
                'result_text'=>"Не удалось использовать переключатель блокировка/разблокировка",
                'started_at'=>date("Y-m-d H:i:s")
            ];
        }
        $this->DB_log($msg);
        
        return $res;
    }

    /**
     * Загружает задачи из БД
     *
     * @return void
     */
    public function DB_get_tasks()
    {
        $db_array = [];

        // $conn = $this->DB_connect();

        $res = $this->conn->query("

        SELECT `id`, `command`, `running_type`, `cron`, `run_on`,`status`
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


    public function return_microtime_string(float $started_at):string
    {   
        $stoped_at = microtime(True);
        $minus = $stoped_at - $started_at;
        $microtime = "{$started_at} {$stoped_at} {$minus}";
        return $microtime;
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
        $task_data['microtime'] = microtime(true);
        $task_data['started_at']=date("Y-m-d H:i:s");
        echo 'Stack id is ' . $task_stack_id . ', task_id is ' . $task_data['id'] . ' command: ' . $task_data['command'] . PHP_EOL;
        // print_r ($task_data);

        $task_data['process'] = proc_open($task_data['command'], $this->descriptorspec, $task_data['pipes']);
        $task_data['pipe1_data'] = '';

        if (!is_resource($task_data['process'])) {
            $this->send_message("error", "process is missing. Task {$task_data['id']} skipped");
            // Todo: ???? Добавить лог неудачного создания процесса


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
        // $this->task_array=[];
        if (empty($this->task_array)) {
            $text='Task array is empty';
            $this->send_message('error', $text);
            $this->DB_log(['result_text'=>$text,'started_at'=>date("Y-m-d H:i:s")]);
            exit();
        }

        foreach ($this->task_array as $task_array_id => &$task_data) {

            // Todo: Добавить простановку статуса declined

            if (!isset($task_data['id']) or empty($task_data['id'])) {
                $text = "Array index {$task_array_id}: field 'id' is missing or empty";
                $this->send_message('error', $text);
                $this->DB_log(['result_text'=>$text,'started_at'=>date("Y-m-d H:i:s")]);
                unset($this->task_array[$task_array_id]);
                continue;
            }

            if (!array_key_exists('command', $task_data) or empty($task_data['command'])) {
                $text = "Task {$task_data['id']}: 'command' field is missing or empty";
                $this->send_message('error', $text);
                $this->DB_log(['result_text'=>$text,'started_at'=>date("Y-m-d H:i:s")]);
                unset($this->task_array[$task_array_id]);
                continue;
            }
            
            if (!array_key_exists('running_type', $task_data)) {
                $text = "Task {$task_data['id']}: 'running_type' field is missing";
                $this->send_message('error', $text);
                $this->DB_log(['result_text'=>$text,'started_at'=>date("Y-m-d H:i:s")]);
                unset($this->task_array[$task_array_id]);
                continue;
            }

            if (!array_key_exists('run_on', $task_data)) {
                $text = "Task {$task_data['id']}: 'run_on' field is missing";
                $this->send_message('error', $text);
                $this->DB_log(['result_text'=>$text,'started_at'=>date("Y-m-d H:i:s")]);
                unset($this->task_array[$task_array_id]);
                continue;
            }

            if (!array_key_exists('cron', $task_data)) {
                $text = "Task {$task_data['id']}: 'cron' field is missing";
                $this->send_message('error', $text);
                $this->DB_log(['result_text'=>$text,'started_at'=>date("Y-m-d H:i:s")]);
                unset($this->task_array[$task_array_id]);
                continue;
            }

            if (!array_key_exists('status', $task_data) or empty($task_data['status'])) {
                $text = "Task {$task_data['id']}: 'status' field is missing or empty";
                $this->send_message('error', $text);
                $this->DB_log(['result_text'=>$text,'started_at'=>date("Y-m-d H:i:s")]);
                unset($this->task_array[$task_array_id]);
                continue;
            }

            $now_datetime = date("Y-m-d H:i:00");
            //Определяем тип задачи: моментальная, по дате или крон
            if(empty($task_data['running_type'])||$task_data['running_type']=='now'){
                // Todo: Выполняется разовая задача
            }
            elseif($task_data['runing_type']=='run_on'){
                // Todo Выполняется задача по дате и времени

                // Проверка не нужна. проверяется выборкой из БД

                if($task_data['run_on'] != $now_datetime){
                    $this->send_message('error', "Task {$task_data['id']}: wrong date.");
                    unset($this->task_array[$task_array_id]);
                }
            }
            elseif($task_data['running_type']=='cron'){
                // Todo: Проверить, правильный ли крон


            }
            else{
                // Todo: Сформировать ошибку в лог и остановить задачу
                // Todo: Записать в БД прочие ошибки и изменить статус задачи на declined
                $this->send_message('error', "Task {$task_data['id']}: wrong 'running_type'");
                if($this->TASKS_set_status_declined($task_data['id'])==1){
                    //log
                    // $this->log_error()
                    unset($this->task_array[$task_array_id]);
                }
            }

        }
        print_r($this->task_array);
        die();
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

        if($this->debug_mod) echo $this->select_process_runing_method . PHP_EOL;
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

    $job = new Tsk;

    $job->debug_mod = True;

    // $job = new B4TaskScheduler;
    
    $job->DB_connect();
    
    $test_log_msg = [
        // 'taskscheduler_id'=>,
        'result_status'=>'1',
        'result_text'=>'Что-то получили от программы',
        'started_at'=>date("Y-m-d H:i:00"),
        'stoped_at'=>date("Y-m-d H:i:01"),
        'microtime'=>'qweasd'
    ];
    // $job->DB_log($test_log_msg);



    // Переводим тестовые задачи в ready, чтобы использовать существующие задачи для тестирования.
    // $job->TEST_TASKS_set_status_ready();

    // выясняем метод работы
    $job->get_process_runing_method();
    
    // загружаем задачи из json
    // $file = 'commands.json';
    // $job->load_from_json($file);
    
    // Ставим задачу на паузу
    // $job->TASKS_set_status_pause(3);

    // Ставим статус ready
    // $job->TASKS_set_status_ready(45);
    // $job->TASKS_set_status('pause',45);

    // Включаем / выключаем задачу
    // $job->TASKS_disable_switch(3);

    // Клонируем задачу
    // $job->TASKS_clone_task(10);

    //Блокируем/разблокируем задачу
    // $job->TASKS_block_switch(45);

    // Удаляем задачу
    // $job->TASKS_delete_tasks(12);

    
    //Создаем задачу
    // $test_task=[
    //     'user'=>0,
    //     'name'=>'test',
    //     'command'=>'sleep 1',
    //     'running_type'=>'cron',
    //     'run_on'=>date("Y-m-d H:i:00"),
    //     'cron'=>"* * * * *"
    // ];
    // $job->TASKS_add_tasks($test_task);

    // Удаляем задачу
    // for ($i=34;$i>11;$i--)
        // $job->TASKS_delete_tasks($i);
    
    
    // Берем список задач
    // $job->DB_get_tasks();
    
    // запускаем на исполение
    // $job->run_procs();

    $job->DB_load_tasks_from_path();

} // end testing
