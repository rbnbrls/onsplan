{{-- Vehicle history PDF (VehicleHistoryExport::pdf). Rendered by dompdf: CSS 2.1 + tables, no flexbox/grid.
     Brand: the default skin's forest green on warm paper, Playfair Display headings over Inter text. --}}
@php
    $v = $d['vehicle'];
    $s = $d['summary'];
    $title = trim($v['name'].($v['year'] ? ' '.$v['year'] : ''));
    $hasCost = fn ($cat) => in_array($cat, ['maintenance', 'repair', 'fuel', 'expense'], true);
@endphp
<!doctype html>
<html lang="{{ $f->lang }}">
<head>
<meta charset="utf-8">
<title>{{ $title }} — {{ $f->t('export_title') }}</title>
<style>
    @font-face { font-family: 'Inter'; font-weight: normal; src: url('{{ $fontDir }}/Inter-Regular.ttf'); }
    @font-face { font-family: 'Inter'; font-weight: bold; src: url('{{ $fontDir }}/Inter-SemiBold.ttf'); }
    @font-face { font-family: 'Playfair'; font-weight: bold; src: url('{{ $fontDir }}/PlayfairDisplay-Bold.ttf'); }
    @page { margin: 22mm 16mm 20mm 16mm; }
    * { box-sizing: border-box; }
    body { font-family: 'Inter', sans-serif; font-size: 9pt; color: #1B3A26; line-height: 1.4; margin: 0; }
    .serif { font-family: 'Playfair', serif; font-weight: bold; }
    .muted { color: #5E7565; }
    .num { text-align: right; white-space: nowrap; }
    .brand { font-size: 7.5pt; letter-spacing: 1.6pt; text-transform: uppercase; color: #2D5E3A; font-weight: bold; }
    /* Running footer on every page (page numbers are drawn on top by dompdf's page_text). */
    .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #8A9A8E; border-top: 0.6pt solid #D9D3C7; padding-top: 2mm; }

    .head { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; padding: 0; }
    .head h1 { font-size: 24pt; line-height: 1.1; margin: 2mm 0 1.5mm; color: #1B3A26; }
    .facts { font-size: 9pt; color: #4A6B52; }
    .facts b { color: #1B3A26; }
    .photo { width: 58mm; height: 38mm; border-radius: 3mm; }
    .rule { height: 2.2pt; background: #2D5E3A; margin: 5mm 0 5mm; }

    /* Four equal tiles flush with the page edges: the gaps are cell padding, the colour is on the inner box. */
    .tiles { width: 100%; border-collapse: collapse; table-layout: fixed; }
    .tiles td { width: 25%; padding: 0 1.5mm; vertical-align: top; }
    .tiles td:first-child { padding-left: 0; }
    .tiles td:last-child { padding-right: 0; }
    .tile { background: #F2EFE7; border-radius: 2.5mm; padding: 3.2mm 3.5mm; }
    .tile .label { font-size: 7pt; text-transform: uppercase; letter-spacing: 0.8pt; color: #5E7565; }
    .tile .value { font-size: 14pt; font-weight: bold; color: #1B3A26; margin-top: 1mm; white-space: nowrap; }

    h2 { font-size: 11pt; margin: 7mm 0 2mm; color: #1B3A26; }
    .bycat { width: 100%; border-collapse: collapse; }
    .bycat td { padding: 1.6mm 0; border-bottom: 0.6pt solid #E4DFD4; }
    .bar { height: 2mm; background: #2D5E3A; border-radius: 1mm; }
    .barbg { background: #E6F0E0; border-radius: 1mm; width: 70mm; }

    .year { width: 100%; border-collapse: collapse; margin-top: 9mm; page-break-after: avoid; }
    .year td { padding: 0 0 1.5mm; border-bottom: 1.2pt solid #2D5E3A; vertical-align: bottom; }
    .year .y { font-size: 20pt; }
    .year .tot { font-size: 10pt; font-weight: bold; }

    .cat { font-size: 9.5pt; font-weight: bold; margin: 5mm 0 1.5mm; color: #2D5E3A; page-break-after: avoid; }
    .cat .muted { font-weight: normal; }
    table.rows { width: 100%; border-collapse: collapse; }
    table.rows th { font-size: 7pt; text-transform: uppercase; letter-spacing: 0.6pt; color: #5E7565; text-align: left; font-weight: normal; padding: 1.2mm 2mm; border-bottom: 0.6pt solid #D9D3C7; }
    table.rows th.num { text-align: right; }
    table.rows td { padding: 1.8mm 2mm; border-bottom: 0.4pt solid #ECE8DF; vertical-align: top; }
    table.rows tr { page-break-inside: avoid; }
    table.rows tr.even td { background: #FAF8F3; }
    table.rows td.date { width: 25mm; white-space: nowrap; }
    table.rows td.km { width: 26mm; }
    table.rows td.cost { width: 28mm; }
    table.rows .sub { font-size: 8pt; color: #5E7565; }
    table.rows tr.total td { border-bottom: 0; font-weight: bold; padding-top: 2mm; background: none; }
    .empty { margin-top: 12mm; text-align: center; color: #5E7565; }
</style>
</head>
<body>
<div class="footer">onsplan · {{ $title }}{{ $v['plate'] ? ' · '.$v['plate'] : '' }} · {{ $f->t('export_generated', ['date' => $f->today()]) }}</div>

<table class="head">
    <tr>
        <td>
            <div class="brand">onsplan · {{ $f->t('export_title') }}</div>
            <h1 class="serif">{{ $title }}</h1>
            @php
                $facts = array_filter([
                    $v['version'] ? e($v['version']) : null,
                    $v['plate'] ? e($f->t('export_plate')).' <b>'.e($v['plate']).'</b>' : null,
                    $v['vin'] ? 'VIN <b>'.e($v['vin']).'</b>' : null,
                ]);
            @endphp
            <div class="facts">{!! implode(' &nbsp;·&nbsp; ', $facts) !!}</div>
            <div class="facts muted" style="margin-top:1mm">{{ $f->t('export_generated', ['date' => $f->today()]) }}</div>
        </td>
        @if ($v['photo'])
            <td style="width:58mm"><img class="photo" src="{{ $v['photo'] }}" alt=""></td>
        @endif
    </tr>
</table>

<div class="rule"></div>

<table class="tiles">
    <tr>
        <td><div class="tile"><div class="label">{{ $f->t('current_mileage') }}</div><div class="value">{{ $f->km($s['mileage_km']) }}</div></div></td>
        <td><div class="tile"><div class="label">{{ $f->t('export_total_spent') }}</div><div class="value">{{ $f->money($s['spent']) }}</div></div></td>
        <td><div class="tile"><div class="label">{{ $f->t('operating_cost', ['unit' => $f->distanceUnit()]) }}</div><div class="value">{{ $f->costPerDistance($s['cost_per_km']) }}</div></div></td>
        <td><div class="tile"><div class="label">{{ $f->t('export_avg_consumption') }}</div><div class="value">{{ $f->consumption($s['avg_l_per_100km']) }}</div></div></td>
    </tr>
</table>

@php $max = max(array_values($d['totals']) ?: [0]) ?: 1; @endphp
@if ($s['spent'] > 0)
    <h2 class="serif">{{ $f->t('export_by_category') }}</h2>
    <table class="bycat">
        @foreach ($d['totals'] as $cat => $amount)
            @if ($amount > 0)
                <tr>
                    <td style="width:45mm">{{ $f->t('export_cat_'.$cat) }}</td>
                    <td><div class="barbg"><div class="bar" style="width:{{ max(2, round($amount / $max * 70)) }}mm"></div></div></td>
                    <td class="num" style="width:35mm">{{ $f->money($amount) }}</td>
                </tr>
            @endif
        @endforeach
    </table>
@endif

@forelse ($d['years'] as $year)
    <table class="year">
        <tr>
            <td class="y serif">{{ $year['year'] }}</td>
            <td class="num tot">@if ($year['total'] > 0){{ $f->t('export_year_total') }} {{ $f->money($year['total']) }}@endif</td>
        </tr>
    </table>

    @foreach ($year['groups'] as $cat => $group)
        <div class="cat">
            {{ $f->t('export_cat_'.$cat) }} <span class="muted">· {{ count($group['rows']) }}</span>
        </div>
        <table class="rows">
            <thead>
                <tr>
                    <th>{{ $f->t('export_col_date') }}</th>
                    <th class="num">{{ $f->t('export_col_odometer') }}</th>
                    <th>{{ $f->t('export_col_details') }}</th>
                    <th class="num">{{ $hasCost($cat) ? $f->t('export_col_cost') : '' }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($group['rows'] as $i => $r)
                    <tr class="{{ $i % 2 ? 'even' : '' }}">
                        <td class="date">{{ $f->date($r['date']) }}</td>
                        <td class="km num">{{ $f->km($r['km']) }}</td>
                        <td>
                            @switch($cat)
                                @case('fuel')
                                    {{ $f->volume($r['liters']) }}{{ $r['full'] ? ' · '.$f->t('export_full_tank') : '' }}
                                    @if ($r['title'])<div class="sub">{{ $r['title'] }}</div>@endif
                                    @break
                                @case('document')
                                    {{ $r['title'] ?: $f->t('doctype_'.$r['doc_type']) }}
                                    @if ($r['title'] && $r['doc_type'])<div class="sub">{{ $f->t('doctype_'.$r['doc_type']) }}</div>@endif
                                    @break
                                @case('mileage')
                                    {{ $f->t('export_odometer_reading') }}
                                    @break
                                @default
                                    {{ $r['title'] ?: $f->t('export_cat_'.$cat) }}
                                    @if ($r['detail'])<div class="sub">{{ $r['detail'] }}</div>@endif
                            @endswitch
                        </td>
                        <td class="cost num">{{ $f->money($r['cost']) }}</td>
                    </tr>
                @endforeach
                @if ($group['total'] > 0 && count($group['rows']) > 1)
                    <tr class="total">
                        <td colspan="3" class="num">{{ $f->t('export_total') }}</td>
                        <td class="cost num">{{ $f->money($group['total']) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    @endforeach
@empty
    <p class="empty">{{ $f->t('timeline_empty_title') }}</p>
@endforelse
</body>
</html>
