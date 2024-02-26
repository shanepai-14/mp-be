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

class ProvisioningVehiclesExport implements FromQuery, WithHeadings, ShouldAutoSize, WithMapping, WithStyles, WithColumnFormatting
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
            'Vendor',
            'Plate Number',
            'Driver Name',
            // 'Mileage',
            'Customer',
            'Registered By',
            'Registered On',
            'Status',
            'Update By',
            'Update On',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'F' => 'yyyy-MMM-dd HH:mm',
            'I' => 'yyyy-MMM-dd HH:mm',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
    }

    public function query()
    {
        $query = Vehicle::query();
        $query = $query->join('vehicle_assignments', 'vehicles.id', '=', 'vehicle_assignments.vehicle_id')
        ->join('current_customers', 'vehicle_assignments.id', '=', 'current_customers.vehicle_assignment_id')
        ->join('customers', 'current_customers.customer_id', '=', 'customers.id')
        ->select('vehicles.*', 'vehicle_assignments.driver_name', 'vehicle_assignments.mileage', 'customers.customer_name', 'vehicle_assignments.vehicle_status');


        if ($this->vendor_id) {
            $query->where('vehicles.transporter_id', '=', $this->vendor_id);
        }

        if ($this->vehicle_status) {
            $query->where('vehicle_assignments.vehicle_status', '=', $this->vehicle_status);
        }


        return $query;
    }

    public function map($vehicle): array
    {
        return [
            $vehicle->vendor->vendor_name,
            $vehicle->device_id_plate_no,
            $vehicle->driver_name,
            // $vehicle->mileage,
            $vehicle->customer_name,
            $vehicle->register_by->full_name,
            Date::dateTimeToExcel($vehicle->created_at),
            $this->_getVehicleStatusTxt($vehicle->vehicle_status),
            $vehicle->updated_by ? $vehicle->updated_by->full_name : $vehicle->register_by->full_name,
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
