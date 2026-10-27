<?php

declare(strict_types=1);

namespace App\Tests\Services\Pricer;

use App\Services\Pricer\Invoke\ParserHandler;
use App\Services\Pricer\Message\ParserMessage;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Сценарий из README: нормальный прайс -> завышенный -> нормальный.
 * Все изменения выполняются в транзакции и откатываются после теста.
 */
class ParserHandlerTest extends KernelTestCase
{
    private const DETAIL_NUM = '3F10010102';

    private ManagerRegistry $registry;
    private Connection $connection;
    private string $csvDir;
    private ?string $lowFile = null;
    private array $report = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->registry = self::getContainer()->get(ManagerRegistry::class);
        $this->connection = $this->registry->getManager()->getConnection();
        $this->csvDir = self::$kernel->getProjectDir() . '/share/csv';
        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM price_MSC');
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        if ($this->lowFile !== null) {
            unlink($this->lowFile);
        }
        parent::tearDown();
    }

    public function testNormalPriceIsAppliedAfterWrongBigPrice(): void
    {
        try {
            $this->runStep('Нормальный', 'FRO2.csv', [null, 2279.0, null, 0], 0);
            $this->runStep('Завышенный', 'FRO2-big.csv', [2279.0, 227900.0, null, 0], 0);
            $this->runStep('Нормальный', 'FRO2.csv', [2279.0, 2279.0, null, 0], 0);
            $this->runStep('Завышенный ещё раз', 'FRO2-big.csv', [2279.0, 227900.0, null, 0], 0);
            $this->runStep('Нормальный', 'FRO2.csv', [2279.0, 2279.0, null, 0], 0);
            $this->runStep('Реальное падение в 100 раз', $this->createLowFile(), [2279.0, 2279.0, 23.0, 1], 15);
        } finally {
            $this->printReport();
        }
    }

    /**
     * @param array $expected [price_prev, price, price_next, need_chek] для DETAIL_NUM
     */
    private function runStep(string $name, string $file, array $expected, int $expectedBlocked): void
    {
        //updated_at хранится с точностью до секунды, а одинаковое значение означает повторную строку в том же прайсе
        sleep(1);
        $path = str_starts_with($file, '/') ? $file : $this->csvDir . '/' . $file;
        (new ParserHandler($this->registry))(new ParserMessage(fileName: $path, delimiter: ';'));

        $row = $this->connection->fetchAssociative(
            'SELECT price_prev, price, price_next, need_chek FROM price_MSC WHERE detail_num = ?',
            [self::DETAIL_NUM]
        );
        $totals = $this->connection->fetchAssociative(
            'SELECT count(*) AS total, coalesce(sum(need_chek), 0) AS blocked FROM price_MSC'
        );
        $actual = [
            $this->toFloat($row['price_prev']),
            $this->toFloat($row['price']),
            $this->toFloat($row['price_next']),
            (int)$row['need_chek'],
        ];
        $this->report[] = [
            $name,
            ...array_map(fn($value) => $value === null ? 'NULL' : (string)$value, $actual),
            sprintf('%d из %d', $totals['blocked'], $totals['total']),
        ];

        $this->assertSame($expected, $actual, "Шаг \"$name\"");
        $this->assertSame($expectedBlocked, (int)$totals['blocked'], "Шаг \"$name\": заблокировано строк");
    }

    private function toFloat(mixed $value): ?float
    {
        return $value === null ? null : (float)$value;
    }

    /**
     * Прайс с ценами в 100 раз ниже нормальных.
     */
    private function createLowFile(): string
    {
        $this->lowFile = tempnam(sys_get_temp_dir(), 'FRO2-low');
        $source = fopen($this->csvDir . '/FRO2.csv', 'r');
        $target = fopen($this->lowFile, 'w');
        while ($row = fgetcsv($source, null, ';', '"', '"')) {
            $row[3] = (string)(int)ceil($row[3] / 100);
            fputcsv($target, $row, ';', '"', '"');
        }
        fclose($source);
        fclose($target);

        return $this->lowFile;
    }

    private function printReport(): void
    {
        $output = new ConsoleOutput();
        $output->writeln(['', 'Артикул ' . self::DETAIL_NUM . ':']);
        (new Table($output))
            ->setHeaders(['Шаг', 'price_prev', 'price', 'price_next', 'need_chek', 'Заблокировано'])
            ->setRows($this->report)
            ->render();
    }
}
