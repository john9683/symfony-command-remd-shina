<?php

declare(strict_types=1);

namespace App\Command;

use App\Docaplus\IDB;
use App\Service\Egisz\Remd\DataProvider;
use App\Util\DateTimeImmutable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;

class ShinaCommitCommand extends Command
{
  /** @var IDB */
  protected $db;

  /** @var DataProvider */
  protected $data;

  public function __construct(IDB $db, DataProvider $data)
  {
    parent::__construct();
    $this->db = $db;
    $this->data   = $data;
  }

  /**
   * @var string $defaultName
   */
  protected static $defaultName = 'app:shina';

  /**
   * @var string $defaultDescription
   */
  protected static $defaultDescription = 'Обновление всех СЭМД с ошибкой шины или в процессе доставки';

  /**
   * @return void
   */
  protected function configure(): void
  {
    $this->setDescription(self::$defaultDescription);
    $this->addArgument('action', InputArgument::OPTIONAL, 'execute: register');
    $this->addOption('month', 'm', InputOption::VALUE_OPTIONAL, 'Месяц начала периода', 01);
    $this->addOption('day', 'd', InputOption::VALUE_OPTIONAL, 'День начала периода', 01);
  }

  public const SQL_SEMD = <<<SQL
SELECT d.NUMBER, d.ID, d.CREATED_AT,
       s.ID_USER, 
       l.STATUS, l.MESSAGE_TYPE
FROM EMDR_DOCUMENT d
    JOIN EMDR_DOCUMENT_SIGN s ON s.ID_DOC = d.ID AND s.ID_USER is not null
    JOIN EMDR_LOG l ON l.ID_DOC = d.ID AND l.ID =
        (SELECT MAX(ID) FROM EMDR_LOG
            WHERE ID_DOC = d.ID)
WHERE d.CREATED_AT > ? AND d.KIND in (41, 90, 147, 205, 206)
ORDER BY s.ID_USER, d.CREATED_AT;
SQL;

  /**
   * @param string $from
   * @return array|null
   */
  public function getSemd(string $from)
  {
    $sqlData = $this->db->rows(self::SQL_SEMD, [$from]);

    $errorSemdArray = [];
    foreach ($sqlData as $item) {
      if ($item['MESSAGE_TYPE'] === 'registerDocumentRequest') {
        $errorSemdArray[] = $item;
      }
    }
    return $errorSemdArray;
  }

  /**
   * @param InputInterface $input
   * @param OutputInterface $output
   * @return void
   */
  protected function execute(InputInterface $input, OutputInterface $output): void
  {
    $fromDefault = (new DateTimeImmutable())::createFromTimestamp(strtotime(date('Y-m-d')))
      ->format('Y-m-01 00:00:00');

    $month = $input->getOption('month');
    $day = $input->getOption('day');
    $from = $month ? (new DateTimeImmutable())::createFromTimestamp(strtotime(date('Y-m-d')))
      ->format('Y-' . $month . '-' . $day . ' 00:00:00') : $fromDefault;

    $semdArray = $this->getSemd($from);

    $console = new SymfonyStyle($input, $output);
    $progress = new ProgressBar($output);
    $progress->setMaxSteps(count($semdArray));

    $rows = [];
    $count = 1;
    $title = 'Найдено СЭМД застрявших в шине, отправленных с '
      . substr($from,0, 10);
    $action = 'action';

    foreach ($semdArray as $semd) {

      switch ($input->getArgument('action')) {

        case 'register':

          $command = 'sudo -u daemon /www/php7/bin/php /www/htdocs/docaplus/seven/bin/console app:remd:reg ' . $semd['NUMBER'];

          system(
            $command,
            $code
          );

          $buffer  = ob_get_clean();
          if ($code === 0) {
            $result = 'register';
          } else {
            $result = $buffer;
          }
          $action = 'REGISTER';
          break;

        default:
          $action = 'STATUS';
          $result = $semd['STATUS'] === 'error' ? $semd['STATUS'] : 'in progress';
          break;
      }

      $rows[] = [
        $count++,
        $semd['ID_USER'],
        $semd['NUMBER'],
        $semd['ID'],
        substr($semd['CREATED_AT'],0, 10),
        $action => $result
      ];

      $progress->advance();
    }

    $progress->finish();

    $table = new Table($output);
    $table
      ->setHeaders(['#', 'ID_USER', 'NUMBER', 'ID_DOC',  'CREATED', $action])
      ->setRows($rows)
    ;

    if (count($semdArray) > 0) {
      $console->title(PHP_EOL . '                          ');
      $console->title('     Команда выполнена     ');
      $table->render();
      $console->warning($title . ': ' . count($semdArray));
    } else {
      $console->success(
        'СЭМД с ошибкой шины, отправленных с '
        . substr($from,0, 10)
        .  ' не найдено');
    }
  }
}
