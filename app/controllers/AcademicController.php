<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class AcademicController extends Controller
{
    private function run(string $method, callable $work, bool $admin = false): void
    {
        $this->call->library('api'); $this->api->require_method($method);
        $auth = $this->api->require_jwt();
        if (($auth['type'] ?? '') !== 'access' || !in_array($auth['role'], ['ADMIN', 'ORGANIZATION', 'STUDENT'], true)
            || ($admin && $auth['role'] !== 'ADMIN')) $this->api->respond_error('Access denied.', 403);
        header('Cache-Control: private, no-store');
        try {
            if ($auth['role'] === 'STUDENT') $this->call->model('StudentAccountModel')->require_session((int) $auth['sub'], $auth['jti']);
            $input = [];
            if ($method === 'POST') {
                if (strtolower(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]) !== 'application/json') throw new DomainException('Use application/json.', 415);
                $input = json_decode(file_get_contents('php://input'), true);
                if (!is_array($input)) throw new DomainException('Invalid JSON.', 400);
            }
            $result = $work($auth, $input);
            $this->api->respond(['success' => true, 'data' => $result]);
        } catch (DomainException $error) { $this->api->respond_error($error->getMessage(), $error->getCode() ?: 422); }
        catch (Throwable $error) { error_log('Academic API: ' . get_class($error)); $this->api->respond_error('Unable to process academic records. Check that the academic_periods migration is applied.', 500); }
    }
    public function periods(): void { $this->run('GET', fn () => $this->call->model('AcademicPeriodModel')->periods()); }
    public function save(): void { $this->run('POST', fn ($auth, $input) => $this->call->model('AcademicPeriodModel')->save($input), true); }
    public function archives(): void { $this->run('GET', fn ($auth) => $this->call->model('FineArchiveModel')->report($auth, $_GET)); }
    public function legacy(): void { $this->run('GET', fn () => $this->call->model('AcademicPeriodModel')->legacy_sources(), true); }
    public function classify(): void { $this->run('POST', fn ($auth, $input) => $this->call->model('AcademicPeriodModel')->classify($input, (int) $auth['sub']), true); }
}
