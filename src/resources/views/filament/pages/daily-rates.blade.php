@php
    $th = 'padding: 6px 8px; background: #5d5950; color: #fff; font-weight: 600; text-align: left; white-space: nowrap;';
    $td = 'padding: 4px 8px; border-bottom: 1px solid rgba(128, 128, 128, .3); text-align: right; white-space: nowrap;';
@endphp

@if ($report !== null)
    <div style="overflow-x: auto; font-size: .875rem;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="{{ $th }}">Rate</th>
                    @foreach ($report['columns'] as $column)
                        <th style="{{ $th }}">{{ $column['name'] }}</th>
                    @endforeach
                </tr>
                <tr>
                    <th style="{{ $th }}">Date</th>
                    @foreach ($report['columns'] as $column)
                        <th style="{{ $th }}">{{ $column['currency'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($report['rows'] as $row)
                    <tr>
                        <td style="{{ $td }} text-align: left;">{{ date('d/m/Y', strtotime($row['date'])) }}</td>
                        @foreach ($row['values'] as $value)
                            <td style="{{ $td }}">{{ $value === null ? '' : number_format($value, 4, ',', '.') }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
