<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') - Lone Oak Billing</title>
    <style>
        body { font: 15px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif; margin: 1rem auto; max-width: 900px; padding: 0 1rem; color: #1f2937; background: #f3f4f6; font-variant-numeric: tabular-nums; }
        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
        h1 { font-size: 1.5rem; margin: 0.4rem 0; }
        h2 { font-size: 1.15rem; margin: 0; }
        p { margin: 0.2rem 0 0.4rem; }
        nav { border-bottom: 1px solid #ddd; padding-bottom: 0.4rem; }
        nav a { margin-right: 1rem; }

        table { border-collapse: collapse; margin: 0.2rem 0 0.5rem; width: 100%; font-size: 0.9rem; }
        th, td { padding: 0.15rem 0.5rem; text-align: left; vertical-align: top; }
        th { color: #6b7280; font-weight: 600; white-space: nowrap; }
        .num { text-align: right; white-space: nowrap; }
        .list-table tr { border-bottom: 1px solid #eef0f2; }

        /* Cards and the invoice header */
        .card { background: #fff; border-radius: 10px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); margin: 1rem 0; padding: 1.25rem 1.5rem; }
        .invoice-card { scroll-margin-top: 1rem; }
        .invoice-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem; }
        .invoice-details { display: grid; grid-template-columns: auto auto; column-gap: 0.75rem; margin: 0.25rem 0 0; font-size: 0.85rem; }
        .invoice-details dt { color: #6b7280; }
        .invoice-details dd { margin: 0; white-space: nowrap; }
        .amount-due-label { font-size: 0.8rem; color: #6b7280; text-align: right; }
        .amount-due { font-size: 2rem; font-weight: 600; line-height: 1.1; white-space: nowrap; text-align: right; }

        /* Small gray secondary text */
        .small, .property-meta, .rate-note, .fee-calculated { font-size: 0.78rem; color: #6b7280; }
        .property-meta { white-space: nowrap; }
        .fee-calculated { text-decoration: line-through; }
        .savings { color: #1a7f37; }

        /* Lines table: widths live on the <col> elements, alignment on the column classes */
        .lines-table { table-layout: fixed; }
        col.col-property { width: 18%; }
        col.col-county-value, col.col-final-value { width: 12%; }
        col.col-savings { width: 11%; }
        col.col-rate { width: 8%; }
        col.col-fee { width: 17%; }
        col.col-note, col.col-amount-due { width: 22%; }
        .col-county-value, .col-final-value, .col-savings, .col-rate, .col-fee, .col-amount-due { text-align: right; white-space: nowrap; }
        .col-rate { padding-left: 0.25rem; padding-right: 0.25rem; }
        .col-fee { padding-left: 1rem; }

        .line-group + .line-group { border-top: 1.25rem solid #fff; }
        .line-header { background: #f3f4f6; }
        .line-header th { padding-top: 0.35rem; padding-bottom: 0.35rem; border-bottom: 1px solid #eef0f2; }
        .line-explain { font-size: 0.85rem; color: #6b7280; padding-top: 0; padding-left: 0; }
        .line-breakdown { padding: 0.75rem 0 0.3rem; }
        .line-total { border-top: 2px solid #e5e7eb; font-weight: 600; }

        /* Tax breakdown table, inside each line group */
        .tax-table { table-layout: fixed; font-size: 0.75rem; margin: 0; }
        col.tax-col-jurisdiction { width: 10%; }
        col.tax-col-rate { width: 9%; }
        col.tax-col-exemption { width: 17%; }
        col.tax-col-amount { width: 12.8%; }
        .col-property { padding-left: 0; }
        .tax-col-jurisdiction, .tax-col-rate, .tax-col-exemption, .tax-col-amount { padding: 0.1rem 0.4rem; }
        .tax-col-jurisdiction { padding-left: 0; }
        .tax-col-rate, .tax-col-amount { text-align: right; white-space: nowrap; }
        .tax-table th { border-bottom: 1px solid #eee; }
    </style>
</head>
<body>
    <nav><a href="{{ route('clients.index') }}">Clients</a></nav>
    @yield('content')
</body>
</html>
