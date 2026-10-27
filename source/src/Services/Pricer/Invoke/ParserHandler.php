<?php

declare(strict_types=1);

namespace App\Services\Pricer\Invoke;

use App\Entity\Makes;
use App\Services\Pricer\Message\ParserMessage;
use App\Services\Pricer\Service\FileRow;
use DateTime;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Generator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ParserHandler
{
    private ParserMessage $message;
    private $fileHandler;
    private DateTime $starTime;
    private array $makes = [];
    private array $makesReplace = [];

    public function __construct(private readonly ManagerRegistry $em)
    {
        $this->starTime = new DateTime();
    }

    public function __invoke(ParserMessage $message): void
    {
        $this->message = $message;
        $this->fileOpen();
        $this->parse();
    }

    private function fileOpen(): void
    {
        if (!file_exists($this->message->getFileName())) {
            throw new Exception('File not exists');
        }
        $this->fileHandler = fopen($this->message->getFileName(), 'r');
    }

    private function parse(): void
    {
        $data = [];
        $i = 0;
        foreach ($this->readFile() as $row) {
            $data[] = $row;
            $i++;
            if ($i > 1000) {
                $this->commit($data);
                $data = [];
                $i = 0;
            }
        }
        $this->commit($data);
    }

    private function readFile(): Generator
    {
        while ($rowRaw = fgetcsv($this->fileHandler, null, $this->message->getDelimiter(), '"', '"')) {
            $data = $this->prepareData($rowRaw);
            if ($data) {
                yield $data;
            }
        }
    }

    private function prepareData(array $rowRaw): array|bool
    {
        $row = new FileRow($rowRaw, $this->message);
        if ($this->rowFilter($row)) {
            return [
                'direction' => $this->message->getDirectionName(),
                'supplier_logo' => $this->message->getSupplierCode(),
                'make_logo' => $row->brand,
                'detail_num' => $row->number,
                'detail_num_supplier' => '',
                'quantity' => $row->quantity,
                'quantity_lot' => $row->quantityLot,
                'price' => $row->price,
                'price_hand' => 0,
                'date' => date("Y-m-d"),
                'date_quant' => date("Y-m-d"),
                'updated_at' => $this->starTime->format("Y-m-d H:i:s"),
                'delivery_time' => 0,
                'make_name_supplier' => $row->brand,
                'need_chek' => 0,
            ];
        }

        return false;
    }

    private function rowFilter(FileRow $row): bool
    {
        return $this->filterEmpty($row);
    }

    private function filterEmpty(FileRow $row): bool
    {
        $empty = [];
        if (empty($row->number)) {
            $empty[] = 'number';
        }
        if (empty($row->price)) {
            $empty[] = 'price';
        }
        if (empty($row->quantity)) {
            $empty[] = 'quantity';
        }

        return empty($empty);
    }

    private function commit(array $data): void
    {
        $coefDangerPriceLess = (100 - $this->message->getPercentDangerPriceLess()) / 100;
        #значительное снижение относительно текущей цены
        $dangerByPrice = "VALUES(price)/price < $coefDangerPriceLess";
        #новая цена близка к предыдущей - текущая цена считается ошибочной и откатывается
        $rollbackToPrev = "price_prev is not null and VALUES(price)/price_prev >= $coefDangerPriceLess";
        $updateFields = [
            'need_chek',
            'quantity',
            'quantity_lot',
            'price_prev',
            'price_next',
            'price',
            'date',
            'date_quant',
            'updated_at',
            'make_name_supplier',
        ];
        # Порядок важен: MySQL применяет присваивания слева направо, поэтому price_prev
        # при $dangerByPrice не меняется, а price и updated_at обновляются последними.
        $valuesToUpdate = [
            #need_chek
            "case
                when updated_at = VALUES(updated_at)
                then 
                    case
                        when VALUES(price) < price
                            then 0
                            else 
                                case
                                    when need_chek is not null
                                    then need_chek
                                    else 0
                                end
                    end
                else
                    case
                        when $dangerByPrice
                            then
                                case
                                    when $rollbackToPrev
                                        then 0
                                        else 1
                                end
                            else 0
                    end
            end",
            "VALUES(quantity)",
            "VALUES(quantity_lot)",
            # price_prev - при откате ошибочная цена в price_prev не попадает
            "case
                when updated_at = VALUES(updated_at)
                then 
                    price_prev
                else
                    case
                        when $dangerByPrice
                            then price_prev
                            else price
                    end
            end",
            #price_next
            "case
                when updated_at = VALUES(updated_at)
                then 
                    price_next
                else
                    case
                        when $dangerByPrice
                            then
                                case
                                    when $rollbackToPrev
                                        then null
                                        else VALUES(price)
                                end
                            else null
                    end
            end",
            #price
            "case
                when updated_at = VALUES(updated_at)
                then 
                    case
                        when VALUES(price) < price
                            then VALUES(price)
                            else price
                    end
                else
                    case 
                        when $dangerByPrice
                            then
                                case
                                    when $rollbackToPrev
                                        then VALUES(price)
                                        else price
                                end
                            else VALUES(price)
                    end
            end",
            "VALUES(date)",
            "VALUES(date_quant)",
            "VALUES(updated_at)",
            "VALUES(make_name_supplier)",
        ];
        if (!empty($data)) {
            $data = $this->additionData($data);
            if (!empty($data)) {
                $this->em->getManager()->getConnection()->upsert(
                    'price_MSC',
                    $data,
                    [],
                    $updateFields,
                    $valuesToUpdate
                );
                $this->em->getManager()->flush();
            }
            $this->makes = [];
        }
    }

    private function additionData(array $data): array
    {
        $this->cacheBrands($data);
        foreach ($data as $index => $row) {
            $make = $this->getMake($row['make_logo']);
            if ($make) {
                $data[$index]['make_logo'] = empty($make->getMakeMatchLogo()) ? $make->getMakeLogo(
                ) : $make->getMakeMatchLogo();
                $data[$index]['make_name_supplier'] = $make->getMakeName();
                $data[$index]['comment'] = '';
                $data[$index]['comment_manager'] = '';
            } else {
                unset($data[$index]);
            }
        }

        return $data;
    }

    private function cacheBrands(array $data): void
    {
        $brands = (array_unique(array_column($data, 'make_logo')));
        $brandArr = [];
        $i = 0;
        foreach ($brands as $brand) {
            $brandArr[] = $brand;
            $i++;
            if ($i > 500) {
                $this->findAllMakes($brandArr);
                $brandArr = [];
                $i = 0;
            }
        }
        $this->findAllMakes($brandArr);
    }

    private function findAllMakes(array $brandArr): void
    {
        if (!empty($brandArr)) {
            $makes = $this->em->getRepository(Makes::class, 'app')->getAllGoodByNames($brandArr);
            if ($makes) {
                foreach ($makes as $make) {
                    $makeName = mb_strtolower($make->getMakeName());
                    $this->makes[$makeName] = $make;
                    $this->makesReplace[$this->getEYoName($makeName)] = $makeName;
                }
            }
        }
    }

    private function getEYoName(string $makeName): string
    {
        return str_replace(['е', 'ё'],
            'е',
            $makeName);
    }

    private function getMake($makeName): Makes|bool
    {
        $makeName = mb_strtolower($makeName);
        if (!$this->checkOriginMake($makeName)) {
            if ($this->checkReplaceMake($makeName) !== false) {
                $makeName = $this->checkReplaceMake($makeName);
            } else {
                return false;
            }
            if (!$this->checkOriginMake($makeName)) {
                return false;
            }
        }

        return $this->makes[$makeName];
    }

    private function checkOriginMake(string $makeName): bool
    {
        return isset($this->makes[$makeName]);
    }

    private function checkReplaceMake(string $makeName): string|false
    {
        return isset($this->makesReplace[$this->getEYoName($makeName)]) ? $this->makesReplace[$this->getEYoName(
            $makeName
        )] : false;
    }

}
