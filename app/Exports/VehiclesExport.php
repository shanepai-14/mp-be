<?php

namespace App\Exports;

use App\Models\Vehicle;
use Illuminate\Support\Facades\Auth;
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

    private $transporter_id;
    private $vehicle_status;


    public function __construct($transporter_id, $vehicle_status)
    {
        $this->transporter_id = $transporter_id;
        $this->vehicle_status = $vehicle_status;
    }

    public function headings(): array
    {
        return [
            'Vendor',
            'Device ID/Plate No',
            'Driver Name',
            'Mileage',
            'Status',
            'Status Updated On',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'F' => 'yyyy-MMM-dd HH:mm'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
    }

    public function query()
    {
        $query = Vehicle::query();

        if ($this->transporter_id) {
            $query->where('transporter_id', '=', $this->transporter_id);
        }

        if ($this->vehicle_status) {
            $query->where('vehicle_status', '=', $this->vehicle_status);
        }

        return $query;
    }

    public function map($vehicle): array
    {
        return [
            $vehicle->vendor->vendor_name,
            $vehicle->device_id_plate_no,
            $vehicle->driver_name,
            $vehicle->mileage,
            $this->_getVehicleStatusTxt($vehicle->vehicle_status),
            Date::dateTimeToExcel($vehicle->updated_at),
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
