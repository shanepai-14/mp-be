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

class VehiclesExport implements FromQuery, WithHeadings, ShouldAutoSize, WithMapping, WithStyles, WithColumnFormatting
{
    use Exportable;

    private $vendor_id;
    private $vehicle_status;


    public function __construct($vendor_id, $vehicle_status)
    {
        $this->vendor_id = $vendor_id;
        $this->vehicle_status = $vehicle_status;
    }

    public function headings(): array
    {
        return [
            'Device ID/Plate No',
            'Driver Name',
            'Mileage',
            'Status',
            'Status Update On',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'E' => 'yyyy-MMM-dd HH:mm'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
    }

    public function query()
    {
        $query = Vehicle::query();

        if ($this->vendor_id) {
            $query->where('vendor_id', '=', $this->vendor_id);
        }

        if ($this->vehicle_status) {
            $query->where('vehicle_status', '=', $this->vehicle_status);
        }

        return $query;
    }

    public function map($vehicle): array
    {
        return [
            $vehicle->device_id_plate_no,
            $vehicle->driver_name,
            $vehicle->mileage,
            $this->_getVehicleStatusTxt($vehicle->vehicle_status),
            $vehicle->updated_by ? Date::dateTimeToExcel($vehicle->updated_at) : null,
        ];
    }

    private function _getVehicleStatusTxt($vehicle_status) {
        switch ($vehicle_status) {
            case 1:
                return 'Approved';
            case 2:
                return 'Rejected';
            case 3:
                return 'Unregistered';
            case 4:
                return 'Pending';
            default:
                return '';
        }
    }
}
