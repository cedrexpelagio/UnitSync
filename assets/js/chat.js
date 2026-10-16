/**
 * UnitSync: i-Cadet Floating Assistant Client Script
 * Plain vanilla JS, no external dependencies.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', initAiCadet);

    function initAiCadet() {
        const toggleBtn = document.getElementById('ai-chat-toggle');
        const panel = document.getElementById('ai-chat-panel');
        if (!toggleBtn || !panel) return;

        const closeBtn = document.getElementById('ai-chat-close');
        const clearBtn = document.getElementById('ai-chat-clear');
        const form = document.getElementById('ai-chat-form');
        const input = document.getElementById('ai-chat-input');
        const sendBtn = document.getElementById('ai-chat-send');
        const messagesContainer = document.getElementById('ai-chat-messages');
        const typingIndicator = document.getElementById('ai-typing-indicator');

        const baseUrl = (panel.getAttribute('data-base-url') || '').replace(/\/+$/, '');
        const csrfToken = panel.getAttribute('data-csrf') || '';
        const currentPage = panel.getAttribute('data-current-page') || 'dashboard.php';
        const role = panel.getAttribute('data-role') || '';
        const firstName = panel.getAttribute('data-first-name') || 'User';

        let isOpen = false;
        let hasLoadedHistory = false;

        // Toggle Open/Close
        function toggleChat(open) {
            isOpen = typeof open === 'boolean' ? open : !isOpen;
            if (isOpen) {
                panel.classList.add('is-open');
                panel.setAttribute('aria-hidden', 'false');
                if (!hasLoadedHistory) {
                    loadChatHistory();
                    hasLoadedHistory = true;
                }
                setTimeout(() => input.focus(), 150);
            } else {
                panel.classList.remove('is-open');
                panel.setAttribute('aria-hidden', 'true');
                toggleBtn.focus();
            }
        }

        toggleBtn.addEventListener('click', () => toggleChat());
        if (closeBtn) closeBtn.addEventListener('click', () => toggleChat(false));

        // Close on Escape key
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && isOpen) {
                toggleChat(false);
            }
        });

        // Auto-resize input textarea
        input.addEventListener('input', () => {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 120) + 'px';
        });

        // Enter sends, Shift+Enter adds newline
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        });

        // Safe HTML escaping
        function escapeHtml(str) {
            if (window.UnitSync && typeof window.UnitSync.esc === 'function') {
                return window.UnitSync.esc(str);
            }
            const div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }

        // Format Markdown text safely (escape first, then apply safe styling)
        function formatMarkdown(text) {
            let escaped = escapeHtml(text);

            // Bold: **text**
            escaped = escaped.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            
            // Inline code: `code`
            escaped = escaped.replace(/`([^`]+)`/g, '<code>$1</code>');

            // Bullets / lists
            const lines = escaped.split('\n');
            let formatted = '';
            let inList = false;

            lines.forEach(line => {
                const trimmed = line.trim();
                if (trimmed.startsWith('• ') || trimmed.startsWith('- ') || trimmed.startsWith('* ')) {
                    if (!inList) {
                        formatted += '<ul>';
                        inList = true;
                    }
                    formatted += `<li>${trimmed.substring(2)}</li>`;
                } else if (/^\d+\.\s/.test(trimmed)) {
                    if (!inList) {
                        formatted += '<ol>';
                        inList = true;
                    }
                    formatted += `<li>${trimmed.replace(/^\d+\.\s/, '')}</li>`;
                } else {
                    if (inList) {
                        formatted += '</ul>';
                        inList = false;
                    }
                    if (trimmed === '') {
                        formatted += '<br>';
                    } else {
                        formatted += `<p>${line}</p>`;
                    }
                }
            });
            if (inList) {
                formatted += '</ul>';
            }

            return formatted;
        }

        // Scroll to bottom of message list
        function scrollToBottom() {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        // Append User Message to UI
        function appendUserMessage(text) {
            const timeStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const msgEl = document.createElement('div');
            msgEl.className = 'ai-msg ai-msg-user';
            msgEl.innerHTML = `
                <div class="ai-msg-bubble">${escapeHtml(text)}</div>
                <span class="ai-msg-time">${timeStr}</span>
            `;
            messagesContainer.appendChild(msgEl);
            scrollToBottom();
        }

        // Append Assistant Message to UI
        function appendAssistantMessage(replyText, actions, messageId, suggestions) {
            const timeStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const msgEl = document.createElement('div');
            msgEl.className = 'ai-msg ai-msg-bot';

            let actionsHtml = '';
            if (Array.isArray(actions) && actions.length > 0) {
                actionsHtml = '<div class="ai-actions-wrap">';
                actions.forEach(act => {
                    if (act.url && act.label) {
                        actionsHtml += `
                            <a href="${escapeHtml(act.url)}" class="ai-action-btn">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="9 18 15 12 9 6"></polyline>
                                </svg>
                                ${escapeHtml(act.label)}
                            </a>
                        `;
                    }
                });
                actionsHtml += '</div>';
            }

            let feedbackHtml = '';
            if (messageId) {
                feedbackHtml = `
                    <div class="ai-msg-feedback" data-msg-id="${messageId}">
                        <button type="button" class="ai-fb-btn ai-fb-up" title="Helpful" aria-label="Mark helpful">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"></path>
                            </svg>
                        </button>
                        <button type="button" class="ai-fb-btn ai-fb-down" title="Not helpful" aria-label="Mark not helpful">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-3"></path>
                            </svg>
                        </button>
                    </div>
                `;
            }

            msgEl.innerHTML = `
                <div class="ai-msg-bubble">
                    ${formatMarkdown(replyText)}
                    ${actionsHtml}
                </div>
                <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                    <span class="ai-msg-time">${timeStr}</span>
                    ${feedbackHtml}
                </div>
            `;

            messagesContainer.appendChild(msgEl);

            // Bind feedback events
            if (messageId) {
                const upBtn = msgEl.querySelector('.ai-fb-up');
                const downBtn = msgEl.querySelector('.ai-fb-down');
                if (upBtn) upBtn.addEventListener('click', () => sendFeedback(messageId, 1, upBtn, downBtn));
                if (downBtn) downBtn.addEventListener('click', () => sendFeedback(messageId, -1, downBtn, upBtn));
            }

            // Render suggestion chips below message if provided
            if (Array.isArray(suggestions) && suggestions.length > 0) {
                renderSuggestions(suggestions);
            }

            scrollToBottom();
        }

        // Render Quick Suggestion Chips
        function renderSuggestions(chips) {
            // Remove previous chips
            const existing = document.getElementById('ai-quick-suggestions');
            if (existing) existing.remove();

            const sugWrap = document.createElement('div');
            sugWrap.id = 'ai-quick-suggestions';
            sugWrap.className = 'ai-quick-suggestions';

            let chipsHtml = '<div class="ai-suggestions-title">Related questions:</div><div class="ai-suggestions-chips">';
            chips.forEach(q => {
                chipsHtml += `<button type="button" class="ai-chip-btn" data-query="${escapeHtml(q)}">${escapeHtml(q)}</button>`;
            });
            chipsHtml += '</div>';

            sugWrap.innerHTML = chipsHtml;
            messagesContainer.appendChild(sugWrap);

            bindChipClicks(sugWrap);
            scrollToBottom();
        }

        // Bind clicks on suggestion chips
        function bindChipClicks(container) {
            const btns = (container || messagesContainer).querySelectorAll('.ai-chip-btn');
            btns.forEach(btn => {
                btn.onclick = function () {
                    const query = this.getAttribute('data-query');
                    if (query) {
                        sendMessage(query);
                    }
                };
            });
        }
        bindChipClicks();

        // Send Feedback (thumbs up / down)
        function sendFeedback(messageId, rating, activeBtn, otherBtn) {
            fetch(baseUrl + '/api/chat_feedback.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    message_id: messageId,
                    rating: rating,
                    csrf_token: csrfToken
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    activeBtn.classList.add(rating === 1 ? 'is-active-up' : 'is-active-down');
                    otherBtn.classList.remove('is-active-up', 'is-active-down');
                    activeBtn.disabled = true;
                    otherBtn.disabled = true;
                    if (typeof showToast === 'function') {
                        showToast(data.message, 'success');
                    }
                }
            })
            .catch(() => {});
        }

        // Load Chat History from Server
        function loadChatHistory() {
            fetch(baseUrl + '/api/chat_history.php', {
                headers: { 'X-CSRF-Token': csrfToken }
            })
            .then(res => res.json())
            .then(data => {
                if (data.ok && Array.isArray(data.data.messages) && data.data.messages.length > 0) {
                    messagesContainer.innerHTML = ''; // Clear default placeholder
                    data.data.messages.forEach(msg => {
                        if (msg.role === 'user') {
                            const msgEl = document.createElement('div');
                            msgEl.className = 'ai-msg ai-msg-user';
                            msgEl.innerHTML = `
                                <div class="ai-msg-bubble">${escapeHtml(msg.content)}</div>
                                <span class="ai-msg-time">${escapeHtml(msg.time_str || '')}</span>
                            `;
                            messagesContainer.appendChild(msgEl);
                        } else {
                            const msgEl = document.createElement('div');
                            msgEl.className = 'ai-msg ai-msg-bot';
                            msgEl.innerHTML = `
                                <div class="ai-msg-bubble">${formatMarkdown(msg.content)}</div>
                                <span class="ai-msg-time">${escapeHtml(msg.time_str || '')}</span>
                            `;
                            messagesContainer.appendChild(msgEl);
                        }
                    });
                    scrollToBottom();
                }
            })
            .catch(() => {});
        }

        // Clear Chat History
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (!confirm('Clear your conversation with i-Cadet?')) return;

                fetch(baseUrl + '/api/chat_history.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({ csrf_token: csrfToken })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.ok) {
                        messagesContainer.innerHTML = `
                            <div class="ai-msg ai-msg-bot">
                                <div class="ai-msg-bubble">
                                    <p><strong>Snappy salute, ${escapeHtml(firstName)}!</strong> 🫡</p>
                                    <p>History cleared. How can I assist you today, Sir/Ma'am?</p>
                                </div>
                                <span class="ai-msg-time">Just now</span>
                            </div>
                        `;
                        if (typeof showToast === 'function') {
                            showToast('Chat history cleared.', 'info');
                        }
                    }
                })
                .catch(() => {
                    if (typeof showToast === 'function') {
                        showToast('Failed to clear history.', 'error');
                    }
                });
            });
        }

        // Submit Question Form
        function sendMessage(text) {
            const cleanText = (text || input.value || '').trim();
            if (!cleanText) return;

            appendUserMessage(cleanText);
            input.value = '';
            input.style.height = 'auto';

            // Disable Send & Show Typing Indicator
            sendBtn.disabled = true;
            input.disabled = true;
            typingIndicator.style.display = 'flex';
            scrollToBottom();

            fetch(baseUrl + '/api/chat.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    message: cleanText,
                    current_page: currentPage,
                    csrf_token: csrfToken
                })
            })
            .then(res => {
                if (res.status === 401) {
                    if (typeof showToast === 'function') showToast('Session expired. Please log in again.', 'warning');
                    setTimeout(() => window.location.reload(), 1500);
                    throw new Error('Session expired');
                }
                if (res.status === 429) {
                    if (typeof showToast === 'function') showToast('Rate limit reached. Please wait a moment.', 'warning');
                }
                return res.json();
            })
            .then(data => {
                if (data.ok && data.data) {
                    appendAssistantMessage(
                        data.data.reply, 
                        data.data.actions, 
                        data.data.message_id, 
                        data.data.suggestions
                    );
                } else {
                    const err = data.message || 'Unable to receive response from i-Cadet.';
                    appendAssistantMessage("I apologize, Sir/Ma'am, but an error occurred while processing: " + err, [], null, []);
                    if (typeof showToast === 'function') showToast(err, 'error');
                }
            })
            .catch(err => {
                if (err.message !== 'Session expired') {
                    appendAssistantMessage("I apologize, Sir/Ma'am, but I cannot reach the server right now. Please check your connection.", [], null, []);
                }
            })
            .finally(() => {
                sendBtn.disabled = false;
                input.disabled = false;
                typingIndicator.style.display = 'none';
                input.focus();
                scrollToBottom();
            });
        }

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            sendMessage();
        });
    }
})();
