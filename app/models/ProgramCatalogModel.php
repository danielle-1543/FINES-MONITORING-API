<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ProgramCatalogModel extends Model
{
    public function catalog(): array
    {
        $programs = [];
        foreach ($this->db->table('academic_programs')
            ->select('code, name')
            ->where('selectable', '=', 1)
            ->order_by('code', 'ASC')
            ->get_all(PDO::FETCH_ASSOC) as $program) {
            $programs[$program['code']] = $program['name'];
        }
        $colleges = $this->db->table('academic_colleges')
            ->select('code')
            ->where('selectable', '=', 1)
            ->order_by('code', 'ASC')
            ->get_all(PDO::FETCH_COLUMN);
        $groups = [];
        foreach ($this->db->table('academic_college_programs')
            ->select('college_code, program_code')
            ->order_by('college_code', 'ASC')
            ->order_by('program_code', 'ASC')
            ->get_all(PDO::FETCH_ASSOC) as $row) {
            $groups[$row['college_code']][] = $row['program_code'];
        }
        if ($programs === [] || $colleges === [] || $groups === []) {
            throw new RuntimeException('The academic program catalog is unavailable.');
        }
        return [
            'student_programs' => $programs,
            'organization_colleges' => $colleges,
            'organization_course_groups' => $groups,
        ];
    }
}
