<?php
/**
 * B4COM (C) 2022
 * Andrey Grey megagramm@gmail.com
 */

class B4TaskScheduler
{
    public function __construct()
    {
        $this->task_count_in_stack = 4;
        $this->select_process_runing_method = 'proc_open'; // Метод работы с процессами // Todo: Сделать альтернативные

        $this->proc_errorlog_path = "b4_error-output.txt";
        $this->errorlog_path = "b4_error.log";

        $this->task_array = []; // массив всех задач
        $this->task_array_empty = false; //Если тру, не позволяем работать с массивом задач
        $this->task_stack = []; // массив задач в работе, ограничен $task_count_in_stack
        $this->tasks_completed = []; // массив выполненных задач

        //подготовка proc_open
        $this->descriptorspec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("file", $this->proc_errorlog_path, "a"),
        );

        $this->permission();
    }

    /**
     * Функция доступа переопределяется в дочернем классе
     *
     * @return void
     */
    public function permission()
    {
    }

    protected function log_error(string $text)
    {
        $handle = fopen($this->errorlog_path, "a");
		$line = date("Y-m-d H:i:s").' | '.$text.PHP_EOL;
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
		$this->log_error($message);
        flush();
        ob_flush();
    }
    /**
     * Загружаем данные из json
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
            $this->send_message('error', 'Json doesn`t decoded');
            $this->task_array_empty = true;
            exit();
        }
        $this->task_array = $array;
    }

    /**
     * Загружает задачи с БД
     *
     * @return void
     */
    public function load_from_db()
    {

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
        echo 'Stack id is ' . $task_stack_id . ', task_id is ' . $task_data['id'] . PHP_EOL;
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

    public function run_procs()
    {
        if ($this->task_array_empty == true) {
            $this->send_message('error', 'run_proc failed to start');
            exit();
        }
        if (empty($this->task_array)) {
            $this->send_message('error', 'Task array is empty');
            exit();
        }

        // проверяем массив задач. // Todo: перенести проверку массива в логичное место
        foreach ($this->task_array as $task_array_id => &$task_data) {
            // todo: добавить логирование неправильных задач
            // Todo:

            if (!isset($task_data['id'])) {
                $this->send_message('error', "Array index {$task_array_id}: field is missing");
                unset($this->task_array[$task_array_id]);
            }

            if (!isset($task_data['command'])) {
                $this->send_message('error', "Task {$task_data['id']}: command field is missing");
                unset($this->task_array[$task_array_id]);
            }

            if (!isset($task_data['date'])) {
                $this->send_message('error', "Task  {$task_data['id']}: date is missing");
                unset($this->task_array[$task_array_id]);
            }

        }

        $this->make_task_stack();

        // запускаем первые процессы стека
        if (isset($this->task_stack) && !empty($this->task_stack)) {
            foreach ($this->task_stack as $task_stack_id => &$task_data) {
                $this->make_proc($task_data, $task_stack_id);
            }
        }

        // exit();
        $i = 0;
        if (isset($this->task_stack) && !empty($this->task_stack)) {
            while (!empty($this->task_stack)) {
                foreach ($this->task_stack as $task_stack_id => &$task_data) {
                    // echo $i . PHP_EOL;
                    $i++;

                    if (!feof($task_data['pipes'][1])) {

                        $task_data['pipe1_data'] .= fgets($task_data['pipes'][1], 1024);
                        echo fgets($task_data['pipes'][1]);
                        // exit;
                    } else {
                        echo 'Stack id(' . $task_stack_id . ') is closed, task_id is ' . $task_data['id'] . PHP_EOL;
                        unset($this->task_stack[$task_stack_id]);
                    }
                    // else {
                    //     fclose($task_data['pipes'][1]);
                    //     proc_close($task_data['process']);
                    //     echo 'Stack id(' . $task_stack_id . ') is closed, task_id is ' . $task_data['id'] . PHP_EOL;
                    //     unset($this->task_stack[0]);
                    // }

                }
            }
        }
    }

    public function select_process_runing_method()
    {
        if (PHP_ZTS == 0) {
            $this->select_process_runing_method = 'proc_open';
        } elseif (PHP_ZTS == 1 && ((PHP_MAJOR_VERSION == 7 && PHP_MINOR_VERSION >= 4) || PHP_MAJOR_VERSION > 7)) {
            $this->select_process_runing_method = 'parallel';
        } else {
            $this->select_process_runing_method = 'ptreads';
        }
        echo $this->select_process_runing_method . PHP_EOL;
    }
}

$file = 'commands.json';
$job = new B4TaskScheduler;
$job->select_process_runing_method();

$job->load_from_json($file);
$job->run_procs();
