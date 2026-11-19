# JARVIS Workflow Step Settings

This is a reference to the step settings currently shown in the JARVIS workflow editor. It describes the controls available for each step type; it does not claim values from a specific saved workflow. Select a step on the canvas to edit it. Every step has an optional **Step name** field. Pause an active workflow before editing.

## Trigger steps

### Email trigger (`email_trigger`)

- **Only from:** Optional sender-address text to match.
- **Subject contains:** Optional subject text to match.
- Checks for new messages once per minute. Existing inbox messages are skipped when the workflow is activated.

### Manual test trigger (`manual_trigger`)

- No additional settings. Starts a test with sample JSON supplied in **Test workflow**; it is not an external event listener.

### Schedule trigger (`schedule_trigger`)

- **Frequency:** Hourly, daily, or weekly.
- **Minute of hour:** Shown for hourly schedules (0–59).
- **Time:** Shown for daily and weekly schedules.
- **Day:** Shown for weekly schedules.
- **Time zone:** The schedule’s timezone. The default is the browser’s timezone.

### Webhook trigger (`webhook_trigger`)

- No additional fields in the step inspector. When activated, JARVIS creates a private HTTPS endpoint. An external app can start the workflow by sending a JSON object to it.

## Processing steps

### Condition (`condition`)

- **Field path:** Value to inspect, such as `quantity` or `result.quantity`.
- **Comparison:** Is not empty, Exists, Equals, Does not equal, or Is greater than.
- **Compare value:** Shown for Equals, Does not equal, and Is greater than.
- The outgoing connections choose the true and false destinations. If a route has no connection, that branch ends.

### Transform (`transform`)

- **Field mapping:** Add, edit, or remove mappings. Each row maps a source field path to an output field name.
- This runs as application code and does not call an AI model.

### Output (`output`)

- No extra settings. Collects results that reach it in run history.

### Validation (`validation`), AI decision (`ai_decision`), and Delay (`delay`)

- The canvas recognizes these process step types, but the current step inspector has no dedicated setting controls for them. Only **Step name** is shown.

## AI and routing steps

### JARVIS AI (`jarvis_ai`)

- **Instruction:** What the AI should do.
- **Input (optional):** Explicit input to analyze. When blank, the previous step’s output is used.
- **Output fields:** Configure the structured values returned by the AI step.
- AI analysis must be enabled in JARVIS settings.

### Orchestrator (`orchestrator`)

- **How it picks an agent:** Pick the best agent for each request, send every request to all agents, or ask which agent to use.
- **Instructions:** Additional routing guidance.
- The inspector lists connected agents and lets you add an AI agent. Routing compares the request with agents’ **Handles** descriptions.

### AI agent (`ai_agent`)

- **Specialization:** Choose a built-in specialty or **Custom**. Built-ins are Secretary, Operations, IT, Sales, Customer Support, HR, Finance, Procurement, Accounting, Legal, Project Manager, Marketing, Data Analyst, Inventory, Logistics, Compliance, Document Manager, and Executive Assistant.
- **Custom specialization:** Shown when **Custom** is selected.
- **Handles:** Topics and requests this agent should receive; used by the orchestrator.
- **Execution mode:** AI-assisted reasoning or deterministic actions.
- **Task type:** Describes the task (default `workflow_execution`).
- **Task priority:** Number from 1 to 100 (default 50).
- **Instructions** and **Output fields:** Shown in AI-assisted mode.
- **Ask me before it sends or changes anything:** Approval control, enabled by default.
- **Apps this agent can use:** Add or remove app steps. Without an app, the agent can only produce text.
- In deterministic mode, connected app actions run without an agent reasoning call.

## App action (`app_action`)

An app step is configured under its agent. The inspector shows **App**, an optional **Version** for apps with variants, **What should it do?** (the action), fields for that action, and a saved **Connection** selector where the integration needs one. Values can be entered directly or mapped from previous data where supported. Available action fields change with the chosen app and action.

| App | Available actions |
| --- | --- |
| Outlook | Read new emails; Create draft reply; Send email |
| Outlook Calendar | List upcoming events; Create event |
| Microsoft Teams | Post a channel message; Send a chat message; Schedule a meeting |
| Microsoft Planner | Create task; Update task; List open tasks |
| Microsoft To Do | Create task |
| SharePoint | Upload a file; Find files; Add a list item |
| OneDrive | Upload a file; Find files |
| Excel (Microsoft 365) | Read table rows; Add a row; Update a row |
| Gmail | List unread inbox messages; Create draft reply; Send email |
| Google Sheets | Read rows; Add a row; Update a row |
| Google Calendar | List upcoming events; Create event |
| MMC Tracker (test connector) | Upsert masterlist record |
| Slack | Send message |
| Trello | Create card |
| Asana | Create task |
| Jira | Create issue; Comment on an issue |
| Notion | Create page |
| Custom API | Call an API |
| SAP Business One | Read records; Create a record; Update a record; Close or cancel a document |
| SAP S/4HANA (OData) | Read records; Create a record; Update a record |
| SAP SuccessFactors | Read records; Create or update a record (upsert) |
| HubSpot | Find contact; Create deal |

### App action fields

Fields depend on the selected action. Common examples include:

- **Email:** To, Subject, Message.
- **Calendar and meetings:** Title, Date and time, Attendees, Days ahead.
- **Tasks and project tools:** Plan, Bucket, Task title, Assignee, Due date, Notes, Project, Issue type, Description, and status/progress.
- **Files and tables:** Site, Library, Folder, File name, Content, Workbook, Table, Spreadsheet, Range, Row values, and row-match criteria.
- **Custom API:** HTTP method (GET, POST, PUT, PATCH, DELETE), HTTPS URL, query parameters, extra headers, optional JSON body, and optional response fields to keep.
- **SAP:** Product/version, service or resource/entity, filters, selected fields, maximum records, record key, JSON body, and (for Business One close/cancel) operation.
- **MMC Tracker:** Duplicate key.

### App-specific notes

- **Gmail:** The app selector lists read, draft, and send actions, while the inspector’s note says that the app node currently supports read-only access. Treat draft/send availability as inconsistent in the current UI and verify support before relying on those actions. Real email sends require **Send new emails** permission in JARVIS settings.
- **SAP:** Choose a product variant. Credentials and server details are held by the saved server-side connection, not in the workflow.
- **MMC Tracker and SAP test behavior:** **Test behavior** lets the test connector simulate success or failure; it does not configure a live connector.
- **Custom API:** Only public HTTPS hosts are allowed. Redirects and private-network addresses are blocked; saved connection secrets are not stored in the workflow or sent as JARVIS credentials.

### MMC Tracker delivery progress through Custom API

Select **Custom API**, action **Call an API**, saved connection **MMC Tracker**, and method **GET**. Use path `/api/jarvis/projects/delivery-progress`, leave extra headers and JSON body blank, and retain the 12-second timeout.

For a specific project summary, set query parameters to `project_id=<verified_project_id>&active_only=0&compact=1`. For project summaries, use `compact=1&per_page=20&page=1`. Set **Response fields to keep** to `data.projects`, `data.summary`, `meta.pagination`, and `meta.filters`, one per line.

Compact mode requires `compact=1` and uses database pagination (20 projects, maximum 50). It calculates only delivery/package/billing aggregates and activity from those records; inventory activity is excluded. Warehouse history, item requirements, pipelines, and definitions are not calculated. Filters include `project_id`, `year`, project `status`, `lot_id`, `region`, `division`, `municipality`, derived `delivery_status`, `active_only`, and `search`. Summary totals cover the returned page; `meta.pagination.total` counts all matching projects. Omitting compact mode preserves the full endpoint, including warehouse data and definitions. Explicit page/per_page parameters can bound the full response. Warehouse readiness and operational pipeline questions require the full response.

Operations questions about project progress use this summary endpoint. Questions about individual delivery/DR records use `/api/jarvis/deliveries` with project, status/date filters, and pagination.

These settings require deployment of the Tracker changes. The deployed unfiltered endpoint timed out during investigation; the local fix has passed fixture tests, but its production performance remains unverified.

## Standalone action steps

### Send email (`send_email`)

- **To**, **Subject**, and **Message:** Enter values directly or map data from another step.
- A real send requires **Send new emails** permission in JARVIS settings. Test runs simulate this step.

### Webhook action (`webhook_action`)

- **HTTPS URL:** Public HTTPS endpoint.
- **Method:** POST, PUT, or PATCH.
- **JSON payload (optional):** Set the request body or leave blank to send the previous step’s output.
- JARVIS credentials are not included. Redirects and private-network addresses are blocked.

## About saved values

These are the controls and choices the editor makes available. To document a particular workflow’s configured values, open that workflow and inspect each step; this file does not represent one workflow’s saved configuration.
