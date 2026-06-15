<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #17211c; font-size: 11px; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        p { color: #647067; margin-top: 0; }
        table { border-collapse: collapse; width: 100%; margin-top: 18px; }
        th, td { border: 1px solid #d8dfda; padding: 6px; text-align: left; }
        th { background: #edf4ef; }
    </style>
</head>
<body>
    <h1>{{ $name }}</h1>
    <p>Generated {{ $result['generated_at'] }} | {{ $result['row_count'] }} rows</p>
    <table>
        <thead><tr>@foreach ($result['columns'] as $column)<th>{{ str_replace('_', ' ', $column) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($result['rows'] as $row)
            <tr>@foreach ($result['columns'] as $column)<td>{{ is_array($row[$column] ?? null) ? json_encode($row[$column]) : ($row[$column] ?? '') }}</td>@endforeach</tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
