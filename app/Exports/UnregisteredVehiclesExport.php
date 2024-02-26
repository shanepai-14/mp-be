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
            'Vendor',
            'Plate Number',
            'Driver Name',
            // 'Mileage',
            'Customer',
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
        $query = $query->join('vehicle_assignments', 'vehicles.id', '=', 'vehicle_assignments.vehicle_id')
        ->join('current_customers', 'vehicle_assignments.id', '=', 'current_customers.vehicle_assignment_id')
        ->join('customers', 'current_customers.customer_id', '=', 'customers.id')
        ->select('vehicles.*', 'vehicle_assignments.driver_name', 'vehicle_assignments.mileage', 'customers.customer_name', 'vehicle_assignments.vehicle_status');

        if ($this->vendor_id) {
            $query->where('vehicles.transporter_id', '=', $this->vendor_id);
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
            Date::dateTimeToExcel($vehicle->created_at),
        ];
    }
}
