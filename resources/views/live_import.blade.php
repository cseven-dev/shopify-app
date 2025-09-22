@extends('layouts.app')

@section('content')
<div class="container">
    <h2>Product Import In Progress</h2>
    <div id="logs" style="background:#eee; border:1px solid #ccc; padding:10px; height:300px; overflow-y:auto;"></div>
    <div id="status" class="mt-3"></div>
</div>

<script>
    let interval;

    function fetchLogs() {
        fetch('/import-progress')
            .then(response => response.json())
            .then(data => {
                const logsDiv = document.getElementById('logs');
                logsDiv.innerHTML = '';
                data.logs.forEach(line => {
                    const p = document.createElement('div');
                    p.textContent = line;
                    logsDiv.appendChild(p);
                });

                if (data.done) {
                    clearInterval(interval);
                    document.getElementById('status').innerHTML = '<strong style="color:green;">✅ Import completed!</strong>';
                }
            });
    }

    window.onload = function () {
        fetch('/start-import', { method: 'POST' });
        interval = setInterval(fetchLogs, 2000);
    };
</script>
@endsection
