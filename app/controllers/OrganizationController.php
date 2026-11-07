<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class OrganizationController extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->call->library('api');
        $this->call->model('OrgEventModel');
    }

    private function run(string $method, callable $work): void
    {
        header('Cache-Control: private, no-store');
        $this->api->require_method($method);
        $auth = $this->api->require_jwt();
        if (($auth['type'] ?? '') !== 'access') {
            $this->api->respond_error('An access token is required.', 401);
        }
        if (($auth['role'] ?? '') !== 'ORGANIZATION') {
            $this->api->respond_error('Organization access only.', 403);
        }
        try {
            $orgId = (int) $auth['sub'];
            $this->OrgEventModel->organization($orgId);
            $this->call->model('AttendanceModel');
            $this->AttendanceModel->finalize($orgId);
            $input = [];
            if ($method === 'POST') {
                $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
                if ($contentType !== 'application/json') {
                    throw new DomainException('Send application/json.', 415);
                }
                $input = json_decode(file_get_contents('php://input'), true);
                if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) {
                    throw new DomainException('Invalid JSON request.', 400);
                }
            }
            $result = $work($orgId, $input);
            $this->api->respond([
                'success' => true
            ] + $result);
        } catch (DomainException $e) {
            $this->api->respond([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 422);
        } catch (Throwable $e) {
            error_log('Organization API: ' . $e->getMessage());
            $this->api->respond([
                'success' => false,
                'message' => 'Unable to process the request. Check the API log.'
            ], 500);
        }
    }

    public function events(): void
    {
        $this->run('GET', fn ($id) => [
            'data' => [
                'events' => $this->OrgEventModel->events($id),
                'types' => $this->OrgEventModel->violation_types($id),
                'scope' => $this->OrgEventModel->scope_info($id)
            ]
        ]);
    }

    public function dashboard(): void
    {
        $this->call->model('OrganizationDashboardModel');
        $this->run('GET', fn ($id) => [
            'data' => $this->OrganizationDashboardModel->overview($id)
        ]);
    }

    public function create_type(): void
    {
        $this->run('POST', function ($id, $data) {
            $this->OrgEventModel->create_type($id, $data);
            return [
                'message' => 'Violation type created.'
            ];
        });
    }

    public function create_event(): void
    {
        $this->run('POST', function ($id, $data) {
            $this->OrgEventModel->create_event($id, $data);
            return [
                'message' => 'Event created. Scanning opens automatically during its time range.'
            ];
        });
    }

    public function edit_event(): void
    {
        $this->run('POST', function ($id, $data) {
            $this->OrgEventModel->edit_event($id, $data);
            return [
                'message' => 'Event updated.'
            ];
        });
    }

    public function records(): void
    {
        $this->run('GET', fn ($id) => $this->OrgEventModel->records_page($id, $_GET));
    }

    public function students(): void
    {
        $this->run('GET', fn ($id) => $this->OrgEventModel->students_page($id, $_GET));
    }

    private function export_data($rows, array $columns): array
    {
        // React writes a real .xlsx workbook from these scoped rows and headers.
        return [
            'data' => $rows->fetchAll(PDO::FETCH_ASSOC),
            'columns' => $columns
        ];
    }

    public function export_records(): void
    {
        $this->run('GET', function ($id) {
            $rows = $this->OrgEventModel->export_records($id, $_GET);
            return $this->export_data($rows, [
                'course' => 'Course',
                'year_level' => 'Year Level',
                'section' => 'Section',
                'full_name' => 'Full Name',
                'student_number' => 'Student Number',
                'event_name' => 'Event',
                'record_type' => 'Record Type',
                'attendance_status' => 'Attendance Status',
                'violation_name' => 'Violation',
                'additional_violations' => 'Additional Violations',
                'fine_amount' => 'Base Fine (PHP)',
                'total_fine' => 'Total Fine (PHP)',
                'recorded_at' => 'Recorded At (PHT)'
            ]);
        });
    }

    public function export_students(): void
    {
        $this->run('GET', function ($id) {
            return $this->export_data($this->OrgEventModel->export_students($id, $_GET), [
                'course' => 'Course',
                'year_level' => 'Year Level',
                'section' => 'Section',
                'full_name' => 'Full Name',
                'student_number' => 'Student Number',
                'account_status' => 'Account Status',
                'activation_status' => 'Activation Status',
            ]);
        });
    }

    public function violation_preview(): void
    {
        $this->run('POST', fn ($id, $data) => [
            'data' => $this->OrgEventModel->preview_violation($id, $data)
        ]);
    }

    public function violation_record(): void
    {
        $this->run('POST', function ($id, $data) {
            $this->OrgEventModel->record_violation($id, $data);
            return [
                'message' => 'Violations recorded.'
            ];
        });
    }

    public function scan_preview(): void
    {
        $this->run('POST', fn ($id, $data) => [
            'data' => $this->OrgEventModel->preview_scan($id, $data)
        ]);
    }

    public function scan_record(): void
    {
        $this->run('POST', function ($id, $data) {
            $this->OrgEventModel->record_scan($id, $data);
            return [
                'message' => 'Record saved successfully.'
            ];
        });
    }
}
