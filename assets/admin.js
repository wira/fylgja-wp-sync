(function ($) {
    'use strict';

    $(function () {
        function toggleSlaveMode() {
            var isSlave = $('#fylgja_role').val() === 'slave';
            $('#fylgja_slave_mode').closest('tr').toggle(isSlave);
        }
        $('#fylgja_role').on('change', toggleSlaveMode);
        toggleSlaveMode();

        $('#fylgja-toggle-key').on('click', function () {
            var $input = $('#fylgja_api_key');
            var masked = $input.attr('type') === 'password';
            $input.attr('type', masked ? 'text' : 'password');
            $(this).text(masked ? 'Hide' : 'Show');
        });

        $('#fylgja-generate-key').on('click', function () {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Generating...');

            $.post(fylgjaSync.ajaxUrl, {
                action: 'fylgja_generate_key',
                nonce: fylgjaSync.nonce
            }, function (response) {
                if (response.success) {
                    $('#fylgja_api_key').val(response.data.key);
                }
                $btn.prop('disabled', false).text('Generate New Key');
            });
        });

        $('#fylgja-sync-now').on('click', function () {
            var $btn = $(this);
            var $status = $('#fylgja-sync-status');

            $btn.prop('disabled', true);
            $status.text('Syncing...');

            $.post(fylgjaSync.ajaxUrl, {
                action: 'fylgja_sync_now',
                nonce: fylgjaSync.nonce
            }, function (response) {
                if (response.success) {
                    var d = response.data;
                    if (d.skipped) {
                        $status.text('A sync is already running.');
                    } else if (d.pending_remaining > 0) {
                        $status.text('Pushed ' + d.pushed + ', failed ' + d.failed +
                            '. ' + d.pending_remaining + ' still draining in the background…');
                    } else {
                        $status.text('Done. Pushed: ' + d.pushed + ', Failed: ' + d.failed);
                    }
                } else {
                    $status.text('Error: ' + response.data);
                }
                $btn.prop('disabled', false);
                refreshQueueStatus();
            });
        });

        var queuePollTimer = null;

        function refreshQueueStatus() {
            $.post(fylgjaSync.ajaxUrl, {
                action: 'fylgja_queue_status',
                nonce: fylgjaSync.nonce
            }, function (response) {
                if (response.success) {
                    var d = response.data;
                    $('#fylgja-count-pending').text(d.pending);
                    $('#fylgja-count-completed').text(d.completed);
                    $('#fylgja-count-failed').text(d.failed);

                    // Keep refreshing while the background drain is working.
                    if (d.pending > 0 && queuePollTimer === null) {
                        queuePollTimer = window.setInterval(refreshQueueStatus, 5000);
                    } else if (d.pending === 0 && queuePollTimer !== null) {
                        window.clearInterval(queuePollTimer);
                        queuePollTimer = null;
                    }
                }
            });
        }
    });
})(jQuery);

(function ($) {
    'use strict';

    if (typeof fylgjaSync === 'undefined') {
        return;
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // received_at_iso carries a timezone offset; render it in the viewer's local
    // timezone. Falls back to the raw (UTC) value if parsing fails.
    function formatReceived(row) {
        var iso = row.received_at_iso;
        if (iso) {
            var d = new Date(iso);
            if (!isNaN(d.getTime())) {
                return d.toLocaleString();
            }
        }
        return row.received_at || '';
    }

    function loadLog() {
        var data = {
            action: 'fylgja_log_query',
            nonce: fylgjaSync.nonce,
            object_type: $('#fylgja-log-filter-type').val(),
            applied: $('#fylgja-log-filter-applied').val()
        };
        $.post(fylgjaSync.ajaxUrl, data, function (resp) {
            if (!resp.success) {
                $('#fylgja-log-table tbody').html('<tr><td colspan="8">Error loading log.</td></tr>');
                return;
            }
            var rows = resp.data.rows || [];
            if (rows.length === 0) {
                $('#fylgja-log-table tbody').html('<tr><td colspan="8">No entries.</td></tr>');
                return;
            }
            var $tbody = $('#fylgja-log-table tbody').empty();
            rows.forEach(function (row) {
                var preview;
                try {
                    preview = JSON.parse(row.preview);
                } catch (e) {
                    preview = { error: 'invalid preview JSON' };
                }
                var warnings = (preview.warnings || []).length;
                var $row = $(
                    '<tr>' +
                    '<td>' + row.id + '</td>' +
                    '<td>' + escapeHtml(formatReceived(row)) + '</td>' +
                    '<td>' + escapeHtml(row.object_type) + '</td>' +
                    '<td>' + escapeHtml(row.action) + '</td>' +
                    '<td>' + row.source_id + '</td>' +
                    '<td>' + (parseInt(row.applied, 10) === 1 ? 'yes' : 'no') + '</td>' +
                    '<td>' + warnings + '</td>' +
                    '<td><button type="button" class="button-link fylgja-log-toggle">View</button></td>' +
                    '</tr>'
                );
                var $detail = $(
                    '<tr class="fylgja-log-detail" style="display:none">' +
                    '<td colspan="8">' +
                    '<strong>Payload:</strong><pre>' + escapeHtml(row.payload) + '</pre>' +
                    '<strong>Preview:</strong><pre>' + escapeHtml(row.preview) + '</pre>' +
                    (row.error ? '<strong>Error:</strong><pre>' + escapeHtml(row.error) + '</pre>' : '') +
                    '</td></tr>'
                );
                $tbody.append($row).append($detail);
            });
        });
    }

    $(document).on('click', '.fylgja-log-toggle', function () {
        $(this).closest('tr').next('.fylgja-log-detail').toggle();
    });

    $(document).on('click', '#fylgja-log-refresh', loadLog);
    $(document).on('click', '#fylgja-log-clear', function () {
        if (!confirm('Clear all sync log entries?')) {
            return;
        }
        $.post(fylgjaSync.ajaxUrl, {
            action: 'fylgja_log_clear',
            nonce: fylgjaSync.nonce
        }, loadLog);
    });

    $(document).on('change', '#fylgja-log-filter-type, #fylgja-log-filter-applied', loadLog);

    $(function () {
        if ($('#fylgja-log-table').length) {
            loadLog();
        }
    });
})(jQuery);

(function ($) {
    'use strict';

    if (typeof fylgjaSync === 'undefined') {
        return;
    }

    var root = document.getElementById('fylgja-resync');
    if (!root) {
        return;
    }

    var $root = $(root);
    var $status = $root.find('.fylgja-resync-status');
    var $start = $root.find('[data-action="start"]');
    var $resume = $root.find('[data-action="resume"]');
    var $cancel = $root.find('[data-action="cancel"]');
    var pollTimer = null;

    function call(action) {
        return $.post(fylgjaSync.ajaxUrl, {
            action: 'fylgja_resync_' + action,
            nonce: fylgjaSync.nonce
        });
    }

    function startPolling() {
        if (pollTimer === null) {
            pollTimer = window.setInterval(function () {
                call('status').done(function (resp) {
                    if (resp && resp.success) {
                        render(resp.data.state);
                    }
                });
            }, 5000);
        }
    }

    function stopPolling() {
        if (pollTimer !== null) {
            window.clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function render(state) {
        if (!state || Object.keys(state).length === 0) {
            $status.text('Idle.');
            $start.prop('hidden', false).prop('disabled', false);
            $resume.prop('hidden', true);
            $cancel.prop('hidden', true);
            stopPolling();
            return;
        }

        if (state.in_progress) {
            var p = state.pushed || {};
            var t = state.totals || {};
            $status.text(
                'Phase: ' + state.phase +
                ' — terms ' + (p.terms || 0) + '/' + (t.terms || 0) +
                ', posts ' + (p.posts || 0) + '/' + (t.posts || 0) +
                ', strings ' + (p.strings || 0) + '/' + (t.strings || 0)
            );
            $start.prop('hidden', true);
            $resume.prop('hidden', true);
            $cancel.prop('hidden', false).prop('disabled', false);
            startPolling();
            return;
        }

        if (state.phase === 'done') {
            var dp = state.pushed || {};
            $status.text(
                'Complete: ' + (dp.terms || 0) + ' terms, ' +
                (dp.posts || 0) + ' posts, ' + (dp.strings || 0) + ' strings.'
            );
            $start.prop('hidden', false).prop('disabled', false);
            $resume.prop('hidden', true);
            $cancel.prop('hidden', true);
            stopPolling();
            return;
        }

        // Paused mid-run: cursor preserved, awaiting resume.
        $status.text('Paused at phase ' + state.phase + ', cursor ' + state.cursor + '.');
        $start.prop('hidden', true);
        $resume.prop('hidden', false).prop('disabled', false);
        $cancel.prop('hidden', true);
        stopPolling();
    }

    function handle(resp) {
        if (!resp || !resp.success) {
            $status.text('Error: ' + (resp && resp.data ? resp.data : 'request failed'));
            return;
        }
        render(resp.data.state);
    }

    $root.on('click', '[data-action]', function (e) {
        e.preventDefault();
        var action = this.dataset.action;

        if (action === 'start' &&
            !window.confirm('Resync All pushes every term, post, and translated string to the slave. Continue?')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);
        call(action).done(handle).fail(function () {
            $status.text('Error: request failed.');
            $btn.prop('disabled', false);
        });
    });

    var initial = {};
    try {
        initial = JSON.parse(root.dataset.state) || {};
    } catch (e) {
        initial = {};
    }
    render(initial);
})(jQuery);
