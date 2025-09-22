// document.addEventListener('DOMContentLoaded', function() {
//     const importBtn = document.getElementById('import-products-btn');
//     const progressContainer = document.getElementById('progress-container');
//     const progressText = document.getElementById('progress-text');
//     const progressBar = document.getElementById('progress-bar');
//     const logList = document.getElementById('log-list');
//     const logsContainer = document.getElementById('import-logs');

//     if (importBtn) {
//         importBtn.addEventListener('click', async function(e) {
//             e.preventDefault();

//             // Reset UI
//             importBtn.disabled = true;
//             importBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Importing...';
//             progressText.textContent = 'Starting import...';
//             progressBar.style.width = '0%';
//             progressBar.textContent = '0%';
//             logList.innerHTML = '';

//             // Show containers
//             progressContainer.classList.remove('hidden');
//             logsContainer.classList.remove('hidden');

//             try {
//                 const response = await fetch(importBtn.dataset.url, {
//                     method: 'POST',
//                     headers: {
//                         'Content-Type': 'application/json',
//                         'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
//                         'Accept': 'text/event-stream',
//                     },
//                 });

//                 if (!response.ok) {
//                     throw new Error(`HTTP error! Status: ${response.status}`);
//                 }

//                 const reader = response.body.getReader();
//                 const decoder = new TextDecoder();
//                 let buffer = '';

//                 while (true) {
//                     const { done, value } = await reader.read();
//                     if (done) break;

//                     buffer += decoder.decode(value, { stream: true });
//                     const parts = buffer.split('\n\n');
//                     buffer = parts.pop() || '';

//                     for (const part of parts) {
//                         if (part.startsWith('data:')) {
//                             const dataStr = part.replace('data:', '').trim();
//                             try {
//                                 const data = JSON.parse(dataStr);

//                                 // Handle progress updates
//                                 if (data.type === 'progress') {
//                                     progressBar.style.width = data.progress + '%';
//                                     progressBar.textContent = data.progress + '%';
//                                     progressText.textContent = data.message;
//                                     addLogItem(data.message, data.success ? 'success' : 'error');
//                                 } 
//                                 // Handle completion
//                                 else if (data.type === 'complete') {
//                                     progressBar.style.width = '100%';
//                                     progressBar.textContent = '100%';
//                                     progressText.textContent = `✅ Import complete! Success: ${data.success_count} | Failed: ${data.failure_count} | Skipped: ${data.skipped_count}`;
//                                     addLogItem(`Import completed. Success: ${data.success_count}, Failed: ${data.failure_count}, Skipped: ${data.skipped_count}`, 'info');
//                                     return; // Exit the loop on completion
//                                 }
//                                 // Handle errors
//                                 else if (data.error) {
//                                     addLogItem(`❌ Error: ${data.error}`, 'error');
//                                     progressText.textContent = 'Import failed';
//                                     return;
//                                 }
//                             } catch (e) {
//                                 console.error('Error parsing SSE data:', e);
//                                 addLogItem('❌ Error processing import data', 'error');
//                             }
//                         }
//                     }
//                 }
//             } catch (error) {
//                 addLogItem(`❌ Import failed: ${error.message}`, 'error');
//                 progressText.textContent = 'Import failed';
//             } finally {
//                 importBtn.disabled = false;
//                 importBtn.innerHTML = '📦 Import Products';
//             }

//             function addLogItem(message, type = 'info') {
//                 const logItem = document.createElement('li');
//                 logItem.className = `p-2 rounded border text-sm mb-1 ${
//                     type === 'success' ? 'bg-green-100 text-green-800 border-green-200' :
//                     type === 'error' ? 'bg-red-100 text-red-800 border-red-200' :
//                     'bg-blue-100 text-blue-800 border-blue-200'
//                 }`;
//                 logItem.textContent = message;
//                 logList.appendChild(logItem);
//                 logList.scrollTop = logList.scrollHeight;
//             }
//         });
//     }
// });



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

            // Create EventSource connection
            const eventSource = new EventSource(importBtn.dataset.url + '?stream=true');

            eventSource.onmessage = function (e) {
                try {
                    const data = JSON.parse(e.data);

                    // Handle progress updates
                    if (data.type === 'progress') {
                        progressBar.style.width = data.progress + '%';
                        progressBar.textContent = data.progress + '%';
                        progressText.textContent = data.message;
                        addLogItem(data.message, 'info');
                    }
                    // Handle completion
                    else if (data.type === 'complete') {
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
                        addLogItem(`❌ ${data.message}`, 'error');
                        progressText.textContent = 'Import failed';
                        eventSource.close();
                        importBtn.disabled = false;
                        importBtn.innerHTML = '📦 Import Products';
                    }
                } catch (error) {
                    addLogItem('❌ Error processing server response', 'error');
                    console.error('Error:', error);
                }
            };

            eventSource.onerror = function () {
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