document.addEventListener('DOMContentLoaded', function () {
    const importBtn = document.getElementById('import-products-btn');
    const progressContainer = document.getElementById('progress-container');
    const progressText = document.getElementById('progress-text');
    const progressBar = document.getElementById('progress-bar');
    const logList = document.getElementById('log-list');
    const logsContainer = document.getElementById('import-logs');

    if (importBtn) {
        importBtn.addEventListener('click', function (e) {
            e.preventDefault();

            // Reset UI
            importBtn.disabled = true;
            importBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Importing...';
            progressText.textContent = 'Starting import...';
            progressBar.style.width = '0%';
            progressBar.textContent = '0%';
            logList.innerHTML = '';

            // Show containers
            progressContainer.classList.remove('hidden');
            logsContainer.classList.remove('hidden');

            // Get shop value from button
            const shop = importBtn.dataset.shop;

            console.log('Starting import for shop:', shop);

            // Create EventSource connection
            const eventSource = new EventSource(importBtn.dataset.url + '?stream=true&shop=' + shop);

            console.log('EventSource created with URL:', importBtn.dataset.url + '?stream=true&shop=' + shop);

            eventSource.onmessage = function (e) {
                try {
                    const data = JSON.parse(e.data);
                    console.log('Received data:', data);
                    // Handle progress updates
                    if (data.type === 'progress') {
                        console.log('Progress update:', data.progress);
                        progressBar.style.width = data.progress + '%';
                        progressBar.textContent = data.progress + '%';
                        progressText.textContent = data.message;
                        addLogItem(data.message, 'info');
                    }
                    // Handle completion
                    else if (data.type === 'complete') {
                        console.log('Import complete:', data);
                        progressBar.style.width = '100%';
                        progressBar.textContent = '100%';
                        progressText.textContent = data.message;
                        addLogItem(`✅ ${data.message} - Success: ${data.success_count}, Failed: ${data.failure_count}, Skipped: ${data.skipped_count}`, 'success');
                        eventSource.close();
                        importBtn.disabled = false;
                        importBtn.innerHTML = '📦 Import Products';
                    }
                    // Handle errors
                    else if (data.type === 'error') {
                        console.log('Import error:', data);
                        addLogItem(`❌ ${data.message}`, 'error');
                        progressText.textContent = 'Import failed';
                        eventSource.close();
                        importBtn.disabled = false;
                        importBtn.innerHTML = '📦 Import Products';
                    }
                } catch (error) {
                    console.log('Error processing message:', e.data);
                    addLogItem('❌ Error processing server response', 'error');
                    console.error('Error:', error);
                }
            };

            eventSource.onerror = function () {
                console.log('EventSource error');
                addLogItem('❌ Connection to server interrupted', 'error');
                progressText.textContent = 'Connection lost';
                eventSource.close();
                importBtn.disabled = false;
                importBtn.innerHTML = '📦 Import Products';
            };

            function addLogItem(message, type) {
                const logItem = document.createElement('li');
                logItem.className = `p-2 rounded border text-sm mb-1 ${type === 'success' ? 'bg-green-100 text-green-800 border-green-200' :
                        type === 'error' ? 'bg-red-100 text-red-800 border-red-200' :
                            'bg-blue-100 text-blue-800 border-blue-200'
                    }`;
                logItem.textContent = message;
                logList.appendChild(logItem);
                logList.scrollTop = logList.scrollHeight;
            }
        });
    }
});