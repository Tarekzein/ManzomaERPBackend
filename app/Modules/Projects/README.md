# Projects Module

Implements SRS section 7: Project & Task Management.

## Coverage

- `FR-PROJ-01`: Projects with name, description, start/end dates, budget, owner, company scope.
- `FR-PROJ-02`: Tasks with priority, status, assignee, and sort order for drag-and-drop boards.
- `FR-PROJ-03`: Gantt timeline payload via `GET /api/projects/gantt`.
- `FR-PROJ-04`: Time logs against tasks with actual vs estimated hour variance.
- `FR-PROJ-05`: Project/task file attachments with comments, stored on `PROJECT_FILESYSTEM_DISK` or S3 by default.
- `FR-PROJ-06`: Project expenses with optional `finance_reference` for Finance module linking.
- `FR-PROJ-07`: Project summary report via `GET /api/projects/{project}/report`.

## API

- `GET /api/projects`
- `POST /api/projects`
- `GET /api/projects/{project}`
- `PATCH /api/projects/{project}`
- `DELETE /api/projects/{project}`
- `GET /api/projects/gantt`
- `GET /api/projects/{project}/report`
- `POST /api/projects/{project}/expenses`
- `GET /api/projects/{project}/tasks`
- `POST /api/projects/{project}/tasks`
- `GET /api/project-tasks/{task}`
- `PATCH /api/project-tasks/{task}`
- `DELETE /api/project-tasks/{task}`
- `POST /api/project-tasks/{task}/time-logs`
- `POST /api/project-tasks/{task}/attachments`
- `POST /api/project-tasks/{task}/comments`
