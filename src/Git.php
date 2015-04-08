<?php
/**
 * Created by PhpStorm.
 * User: pavel
 * Date: 08.04.15
 * Time: 18:18
 */

namespace PavelEkt\Wrappers;

use PavelEkt\Wrappers\Shell as ShellWrapper;

class Git
{
    const LAST_UPDATE_FORMAT_TIMESTAMP = 'timestamp';
    const LAST_UPDATE_FORMAT_GIT = 'default';
    const LAST_UPDATE_FORMAT_MYSQL = 'Y-m-d H:i:s';

    /**
     * @var \PavelEkt\Wrappers\Shell $shell компонент для работы с оболочкой.
     */
    protected $shell = null;

    /**
     * @var string $gitCommand полный путь до исполняемого файла гита
     */
    public $gitCommand = '/usr/bin/git';

    /**
     * Конструктор
     *
     * Если при создании экземпляра класса не передаем экземпляр [[\console\components\ShellComponent|ShellComponent]],
     * то создадим его, т.к. он необходим для работы.
     *
     * @param \PavelEkt\Wrappers\Shell|null $shell Компонент работы с оболочкой.
     */
    public function __construct($shell = null)
    {
        if ($shell instanceof ShellWrapper) {
            $this->shell = $shell;
        } else {
            $this->shell = new ShellWrapper(__DIR__);
        }
    }

    /**
     * Смена рабочей директории.
     *
     * Переходит в рабочую директорию, в которой выполняются команды, указанной в передаваемом значении.
     * Детально смотреть описание метода cd класса [[\PavelEkt\Wrappers\Shell]].
     *
     * @param string $path Каталог, в который необходимо перейти.
     * @return bool
     */
    public function cd($path)
    {
        return $this->shell->cd($path);
    }

    /**
     * Определяет текущую локальную ветку.
     *
     * @return string|false
     */
    public function currentBranch()
    {
        $branchDetectStr = 'On branch ';
        $out = $this->status();
        if ($out !== false && !empty($out[0]) && strpos($out[0], $branchDetectStr) !== false) {
            return substr($out[0], strpos($out[0], $branchDetectStr) + strlen($branchDetectStr));
        }
        return false;
    }

    /**
     * Возвращает время последнего обновления ветки
     *
     * @param null $branchName имя ветки
     * @param string $format формат времени
     * возможно использовать:
     * self::LAST_UPDATE_FORMAT_TIMESTAMP ('timestamp') для вывода timestamp,
     * self::LAST_UPDATE_FORMAT_GIT ('default') для вывода времени в формате git log,
     * self::LAST_UPDATE_FORMAT_MYSQL ('Y-m-d H:i:s') для вывода времени в формате mysql,
     * любой другой формат поддерживаемый ф-ей date
     * @return int|string|false
     */
    public function lastUpdateDate($branchName = null, $format = self::LAST_UPDATE_FORMAT_TIMESTAMP)
    {
        $dateDetectStr = 'Date:   ';
        $log = $this->log($branchName, 1);
        if ($log !== false && !empty($log[3]) && strpos($log[3], $dateDetectStr) !== false) {
            $gitTime = substr($log[3], strpos($log[3], $dateDetectStr) + strlen($dateDetectStr));
            switch ($format) {
                case self::LAST_UPDATE_FORMAT_GIT:
                    return $gitTime;
                    break;
                case self::LAST_UPDATE_FORMAT_TIMESTAMP:
                    return strtotime($gitTime);
                    break;
                default:
                    return date($format, strtotime($gitTime));
            }
        }
        return false;
    }

    /**
     * Возвращает список файлов измененных между ветками.
     *
     * Если вторая ветка не указана, вернет разницу между текущей веткой и веткой указанной в $first
     * Если вторая ветка младше первой, статус файлов будет противоположный.
     *
     * @param string $firstBranchName имя первой ветки
     * @param string|null $secondBranchName имя второй ветки
     * @return mixed[]|bool
     * При успешном вызове вернет массив типа
     * '''php
     * [
     *     // Список добавленных файлов
     *     'new' => [
     *         'common/model/user.php'
     *     ],
     *     // Список измененных файлов
     *     'modify' => [
     *         'common/model/role.php'
     *     ],
     *     // Список удаленных файлов
     *     'remove => [
     *         'common/model/auth.php'
     *     ]
     * ]
     * '''
     */
    public function diffFileList($firstBranchName, $secondBranchName = null)
    {
        $out = [];
        $result = ['new' => [], 'modify' => [], 'remove' => []];

        if (
            $this->runGitCommand(
                'diff --name-status ' . $firstBranchName . (!empty($secondBranchName) ? ' ' . $secondBranchName : ''),
                $out
            ) === 0
        ) {
            foreach ($out['stdout'] as $row) {
                $params = explode("\t", $row);
                if (!empty($params)) {
                    switch ($params[0]) {
                        case 'A':
                        case 'a':
                            $key = 'new';
                            break;
                        case 'M':
                        case 'm':
                            $key = 'modify';
                            break;
                        case 'D':
                        case 'd':
                            $key = 'remove';
                            break;
                    }
                    $result[$key][] = $params[1];
                }
            }
            return $result;
        }
        return false;
    }

    /**
     * Получает статус текущей ветки.
     *
     * @return string[]|bool
     */
    public function status()
    {
        $out = [];
        if ($this->runGitCommand('status', $out) === 0) {
            return $out['stdout'];
        }
        return false;
    }

    /**
     * Возвращает лог.
     *
     * Возвращает лог определенной ветки. Если ветка не указана, вернет лог текущей ветки.
     *
     * @param string|null $branchName имя ветки
     * @param integer|null $linesCount количество строк
     * @return string[]|bool
     */
    public function log($branchName = null, $linesCount = null)
    {
        $out = [];
        if (
            $this->runGitCommand(
                'log' . (is_int($linesCount) ? ' -n' . $linesCount : '') .
                (!empty($branchName) ? ' ' . $branchName : ''),
                $out
            ) === 0
        ) {
            return $out['stdout'];
        }
        return false;
    }

    /**
     * Стягивает изменения в текущей ветке.
     *
     * @param bool $isOrigin стянуть изменения с сервера.
     * @param bool $isQuiet тихий режим
     * @return bool
     */
    public function pull($isOrigin = false, $isQuiet = true)
    {
        $out = [];
        return $this->runGitCommand('pull' . ($isQuiet ? ' -q' : '') . ($isOrigin ? ' origin' : ''), $out) === 0;
    }

    /**
     * Сменяет ветку.
     *
     * @param string $branchName имя ветки
     * @param bool $isOrigin переключаться на серверную?
     * @param bool $isQuiet тихий режим
     * @return bool
     */
    public function checkout($branchName, $isOrigin = false, $isQuiet = true)
    {
        $out = [];
        return $this->runGitCommand(
            'checkout ' . ($isQuiet ? '-q ' : '') . ($isOrigin ? 'origin/' : '') . $branchName,
            $out
        ) === 0;
    }

    /**
     * Получаем обновление веток сервера обновлений.
     *
     * @return bool
     */
    public function fetchOrigin()
    {
        $out = [];
        return $this->runGitCommand('fetch origin', $out) === 0;
    }

    /**
     * Заносим изменения в тайник.
     *
     * @return bool
     */
    public function stash()
    {
        $out = [];
        return $this->runGitCommand('stash', $out) === 0;
    }

    /**
     * Выводит результаты последней выполненой операции.
     *
     * Более детально смотри описание в классе [[класса \console\components\ShellComponent|ShellComponent]]
     *
     * @return mixed[]
     */
    public function getLastResult()
    {
        return $this->shell->getLastResult();
    }

    /**
     * Запускает команду git с параметрами.
     *
     * @param string $command параметры команды git.
     * @param mixed[] &$out если передан, заполнится выводом команды.
     * @return integer заполнится кодом завершения
     */
    protected function runGitCommand($command, &$out)
    {
        return $this->shell->exec($this->gitCommand . ' ' . $command, $out);
    }
}
