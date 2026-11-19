<?php

namespace App\Services\Jarvis;

class TestArtifactFactory
{
    /** @return array{response: string, artifacts: array<int, array<string, mixed>>}|null */
    public function responseFor(string $command): ?array
    {
        $query = mb_strtolower($command);
        $query = trim(preg_replace('/[^a-z0-9]+/i', ' ', $query) ?? '');
        $query = preg_replace('/\s+/', ' ', $query) ?? $query;

        if ($this->isAmbiguousHrGraph($query)) {
            return [
                'response' => 'What would you like to graph: attendance, lateness, leave, or HR task status?',
                'artifacts' => [],
            ];
        }

        if ($query === 'lateness' || (str_contains($query, 'late') && str_contains($query, 'graph'))) {
            return $this->result('Here is a test chart of employee lateness.', [$this->attendanceChart()]);
        }

        if ($this->isWorkspaceCommand($query) || $this->isOperationsDashboardCommand($query)) {
            return $this->result('Here is the test Operations workspace. All three panels contain mock data.', $this->operationsDashboard());
        }

        if ($this->isWorkflowFailureCommand($query)) {
            return $this->result('Here is a simulated workflow failure. SAP is marked as failed; no integration was contacted.', [$this->workflowExecution(failed: true)]);
        }

        if ($this->isWorkflowExecutionCommand($query)) {
            return $this->result('Here is a simulated workflow execution. No integration was contacted.', [$this->workflowExecution(failed: false)]);
        }

        if ($this->isAgentStatusCommand($query)) {
            return $this->result('Here is a test status overview for the Agents.', [$this->agentStatus()]);
        }

        if ($this->isEmailDraftCommand($query)) {
            return $this->result('Here is an editable test email draft. It will not be sent.', [$this->emailDraft()]);
        }

        if ($this->isOperationsReportCommand($query)) {
            return $this->result('Here is a test Operations report.', [$this->operationsSummary()]);
        }

        if ($this->isSyncCommand($query)) {
            return $this->result('Here is a simulated SAP synchronization status. These records are test data.', [$this->syncStatus()]);
        }

        if ($this->isApprovalCommand($query)) {
            return $this->result('Here is a test approval card. Approving it only changes its simulated status.', [$this->approval()]);
        }

        if ($this->isTaskListCommand($query)) {
            return $this->result('Here is a test task list.', [$this->taskList()]);
        }

        if ($this->isAttendanceReportCommand($query) || $this->isReportCommand($query)) {
            return $this->result('Here is a test attendance report.', [$this->attendanceReport()]);
        }

        if ($this->isChartCommand($query)) {
            return $this->result('Here is a test chart of employee lateness.', [$this->attendanceChart()]);
        }

        if ($this->isWorkflowGraphCommand($query)) {
            return $this->result('Here is a test Operations workflow graph.', [$this->workflowGraph()]);
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public function operationsDashboard(): array
    {
        return [
            $this->operationsSummary(),
            $this->workflowGraph(),
            $this->failedRecords(),
        ];
    }

    /** @return array<string, mixed> */
    public function workflowGraph(): array
    {
        return $this->artifact('test-operations-graph', 'workflow_graph', 'Operations Workflow', [
            'nodes' => [
                ['id' => 'operations', 'type' => 'agent', 'label' => 'Operations Agent', 'status' => 'success'],
                ['id' => 'masterlist', 'type' => 'app', 'label' => 'Operation Masterlist', 'status' => 'success'],
                ['id' => 'tracker', 'type' => 'app', 'label' => 'MMC Tracker', 'status' => 'success'],
                ['id' => 'sap', 'type' => 'app', 'label' => 'SAP Business One', 'status' => 'success'],
            ],
            'edges' => [
                ['source' => 'operations', 'target' => 'masterlist', 'status' => 'success'],
                ['source' => 'masterlist', 'target' => 'tracker', 'status' => 'success'],
                ['source' => 'masterlist', 'target' => 'sap', 'status' => 'success'],
            ],
        ], 'Operations Agent');
    }

    /** @return array<string, mixed> */
    public function attendanceChart(): array
    {
        return $this->artifact('test-employee-lateness', 'chart', 'Employee Lateness', [
            'chart_type' => 'bar',
            'labels' => ['Employee A', 'Employee B', 'Employee C', 'Employee D'],
            'values' => [8, 6, 5, 3],
        ], 'HR Agent');
    }

    /** @return array<string, mixed> */
    public function attendanceReport(): array
    {
        return $this->artifact('test-hr-attendance-report', 'report', 'HR Attendance Report', [
            'summary' => 'Three sample employees are included for renderer testing.',
            'metrics' => ['Employees' => 3, 'Period' => 'Test period'],
            'columns' => ['Employee', 'Late', 'Absent'],
            'rows' => [
                ['Employee' => 'Employee A', 'Late' => 8, 'Absent' => 1],
                ['Employee' => 'Employee B', 'Late' => 6, 'Absent' => 0],
                ['Employee' => 'Employee C', 'Late' => 5, 'Absent' => 2],
            ],
        ], 'HR Agent');
    }

    /** @return array<string, mixed> */
    public function emailDraft(): array
    {
        return $this->artifact('test-email-draft', 'email', 'Supplier Follow-up', [
            'to' => 'supplier@example.com',
            'cc' => '',
            'subject' => 'Follow-up on Delivery',
            'body' => "Hello,\n\nCould you share an update on the delivery timeline?\n\nThank you.",
        ], 'Secretary Agent', ['save_draft', 'send_email']);
    }

    /** @return array<string, mixed> */
    public function operationsSummary(): array
    {
        return $this->artifact('test-operations-summary', 'report', 'Operations Summary', [
            'summary' => 'Simulated masterlist synchronization summary.',
            'metrics' => ['Processed' => 43, 'Successful' => 41, 'Failed' => 2],
        ], 'Operations Agent');
    }

    /** @return array<string, mixed> */
    public function failedRecords(): array
    {
        return $this->artifact('test-failed-records', 'table', 'Failed Records', [
            'columns' => ['Record', 'Destination', 'Error'],
            'rows' => [
                ['Record' => 'TEST-001', 'Destination' => 'SAP', 'Error' => 'Validation Error'],
                ['Record' => 'TEST-002', 'Destination' => 'SAP', 'Error' => 'Item not found'],
            ],
        ], 'Operations Agent', ['retry']);
    }

    /** @return array<string, mixed> */
    public function agentStatus(): array
    {
        return $this->artifact('test-agent-status', 'agent_status', 'Agent status', [
            'agents' => [
                ['label' => 'HR Agent', 'status' => 'idle', 'today' => ['successful' => 12, 'failed' => 0, 'processed' => 12]],
                ['label' => 'Operations Agent', 'status' => 'needs_attention', 'today' => ['successful' => 41, 'failed' => 2, 'processed' => 43]],
                ['label' => 'Secretary Agent', 'status' => 'working', 'today' => ['successful' => 0, 'failed' => 0, 'processed' => 0], 'current_task' => ['type' => 'Preparing email', 'status' => 'running']],
            ],
        ], 'JARVIS supervision');
    }

    /** @return array<string, mixed> */
    public function workflowExecution(bool $failed): array
    {
        $graph = $this->workflowGraph();
        $graph['id'] = $failed ? 'test-workflow-failure' : 'test-workflow-execution';
        $graph['type'] = 'workflow_run';
        $graph['title'] = $failed ? 'Test Workflow Failure' : 'Test Workflow Execution';
        $graph['status'] = 'executing';
        $graph['actions'] = ['retry', 'refresh'];
        $graph['data']['simulation'] = $failed ? 'failure' : 'success';
        $graph['data']['message'] = 'Simulated execution; no external systems will be contacted.';
        $graph['data']['nodes'] = array_map(
            static fn (array $node): array => [...$node, 'status' => 'waiting'],
            $graph['data']['nodes'],
        );
        $graph['data']['edges'] = array_map(
            static fn (array $edge): array => [...$edge, 'status' => 'waiting'],
            $graph['data']['edges'],
        );

        return $graph;
    }

    /** @return array<string, mixed> */
    public function syncStatus(): array
    {
        return $this->artifact('test-sap-sync-status', 'sync_status', 'SAP Synchronization', [
            'summary' => 'This synchronization is simulated for interface testing.',
            'metrics' => ['Processed' => 43, 'Successful' => 41, 'Failed' => 2],
        ], 'Operations Agent', ['refresh', 'retry']);
    }

    /** @return array<string, mixed> */
    public function taskList(): array
    {
        return $this->artifact('test-task-list', 'task_list', 'Test Agent Tasks', [
            'columns' => ['Task', 'Owner', 'Status'],
            'tasks' => [
                ['Task' => 'Review attendance sample', 'Owner' => 'HR Agent', 'Status' => 'Completed'],
                ['Task' => 'Validate SAP sample rows', 'Owner' => 'Operations Agent', 'Status' => 'Needs attention'],
            ],
        ], 'Orchestrator', ['refresh']);
    }

    /** @return array<string, mixed> */
    public function approval(): array
    {
        return $this->artifact('test-approval', 'approval', 'Test Approval', [
            'summary' => 'Review this simulated action. Approval will not call an Agent or an external system.',
            'owner' => 'HR Agent',
        ], 'HR Agent', ['approve', 'reject']);
    }

    private function isAmbiguousHrGraph(string $query): bool
    {
        return str_contains($query, 'hr') && str_contains($query, 'graph') && ! preg_match('/attendance|lateness|late|leave|task|status/', $query);
    }

    private function isWorkspaceCommand(string $query): bool
    {
        return preg_match('/\btest\s+(?:the\s+)?(?:jarvis\s+)?workspace\b/', $query) === 1
            || preg_match('/\bworkspace\s+test\b/', $query) === 1
            || str_contains($query, 'test operations dashboard');
    }

    private function isOperationsDashboardCommand(string $query): bool
    {
        return (str_contains($query, 'operation') && (str_contains($query, 'dashboard') || str_contains($query, 'status')))
            || (str_contains($query, 'today') && str_contains($query, 'operation') && str_contains($query, 'sync'));
    }

    private function isWorkflowFailureCommand(string $query): bool
    {
        return str_contains($query, 'workflow failure') || str_contains($query, 'workflow fail');
    }

    private function isWorkflowExecutionCommand(string $query): bool
    {
        return str_contains($query, 'workflow execution');
    }

    private function isAgentStatusCommand(string $query): bool
    {
        return str_contains($query, 'agent') && (str_contains($query, 'status') || str_contains($query, 'test agents') || str_contains($query, 'how are'));
    }

    private function isEmailDraftCommand(string $query): bool
    {
        return str_contains($query, 'test email')
            || (str_contains($query, 'email') && preg_match('/\b(draft|create|write|compose|prepare|send)\b/', $query) === 1);
    }

    private function isSyncCommand(string $query): bool
    {
        return str_contains($query, 'test sync')
            || (str_contains($query, 'sap') && (str_contains($query, 'sync') || str_contains($query, 'synchronization')));
    }

    private function isApprovalCommand(string $query): bool
    {
        return str_contains($query, 'test approval');
    }

    private function isTaskListCommand(string $query): bool
    {
        return str_contains($query, 'test task') || str_contains($query, 'test tasks');
    }

    private function isAttendanceReportCommand(string $query): bool
    {
        return str_contains($query, 'attendance') && str_contains($query, 'report');
    }

    private function isReportCommand(string $query): bool
    {
        return str_contains($query, 'test report') || (str_contains($query, 'hr') && str_contains($query, 'report'));
    }

    private function isOperationsReportCommand(string $query): bool
    {
        return str_contains($query, 'operation') && str_contains($query, 'report');
    }

    private function isChartCommand(string $query): bool
    {
        return str_contains($query, 'test chart') || str_contains($query, 'lateness')
            || (str_contains($query, 'employee') && str_contains($query, 'late') && str_contains($query, 'chart'));
    }

    private function isWorkflowGraphCommand(string $query): bool
    {
        return str_contains($query, 'test graph') || str_contains($query, 'test workflow')
            || (str_contains($query, 'workflow') && str_contains($query, 'graph'));
    }

    /** @param array<int, array<string, mixed>> $artifacts */
    private function result(string $response, array $artifacts): array
    {
        return ['response' => $response, 'artifacts' => $artifacts];
    }

    /** @param array<string, mixed> $data
     * @param  array<int, string>  $actions
     * @return array<string, mixed>
     */
    private function artifact(string $id, string $type, string $title, array $data, string $owner, array $actions = []): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'subtitle' => 'Deterministic demo fixture',
            'owner_agent' => $owner,
            'source' => 'JARVIS Test Artifact Factory',
            'status' => 'ready',
            'data_kind' => $type === 'email' ? 'generated_content' : 'execution_state',
            'priority' => 'normal',
            'generated_at' => now()->toIso8601String(),
            'data' => $data,
            'actions' => $actions,
            'refreshable' => in_array('refresh', $actions, true),
            'is_mock' => true,
            'environment' => 'test',
        ];
    }
}
