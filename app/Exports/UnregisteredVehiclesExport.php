<?php

namespace App\Exports;

use App\Models\Vehicle;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class UnregisteredVehiclesExport implements FromQuery, WithHeadings, ShouldAutoSize, WithMapping, WithStyles, WithColumnFormatting
{
    use Exportable;

    private $transporter_id;


    public function __construct($transporter_id)
    {
        $this->transporter_id = $transporter_id;
    }

    public function headings(): array
    {
        return [
            'Transporter Name',
            'Device ID/Plate No',
            'Driver Name',
            'Mileage',
            'Data Received On',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'E' => 'yyyy-MMM-dd HH:mm',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
    }

    public function query()
    {
        $query = Vehicle::query()->where('vehicle_status', '=', 3);

        if ($this->transporter_id) {
            $query->where('transporter_id', '=', $this->transporter_id);
        }

        return $query;
    }

    public function map($vehicle): array
    {
        return [
            $vehicle->transporter->transporter_name,
            $vehicle->device_id_plate_no,
            $vehicle->driver_name,
            $vehicle->mileage,
            Date::dateTimeToExcel($vehicle->created_at),
        ];
    }
}
