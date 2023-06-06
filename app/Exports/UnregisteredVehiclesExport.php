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

    private $vendor_id;


    public function __construct($vendor_id)
    {
        $this->vendor_id = $vendor_id;
    }

    public function headings(): array
    {
        return [
            'Vendor Name',
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

        if ($this->vendor_id) {
            $query->where('vendor_id', '=', $this->vendor_id);
        }

        return $query;
    }

    public function map($vehicle): array
    {
        return [
            $vehicle->vendor->vendor_name,
            $vehicle->driver_name,
            $vehicle->device_id_plate_no,
            $vehicle->mileage,
            Date::dateTimeToExcel($vehicle->created_at),
        ];
    }
}
