{*
 * LogicBoxes Hosting - client dashboard.
 * Customise: copy this file to ../overrides/templates/clientarea.tpl (kept
 * across updates). Sections can also be switched off under
 * Addons > LogicBoxes Hosting > Settings > Client area.
 * Every variable is escaped; nothing here comes from another client.
 *}
{literal}<style>
.lbh-dash .lbh-actions form{display:inline-block;margin:0 6px 6px 0}
.lbh-dash .lbh-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;color:#fff;background:#777}
.lbh-dash .lbh-badge.success{background:#3c9a5f}.lbh-dash .lbh-badge.warning{background:#d98c1f}.lbh-dash .lbh-badge.danger{background:#c9453c}
.lbh-dash code{word-break:break-all}
.lbh-dash .card,.lbh-dash .panel{margin-bottom:20px}
</style>{/literal}
<div class="lbh-dash">
{if $lbh_ok && $lbh_notice_lines}
    <div class="alert alert-info">{foreach $lbh_notice_lines as $line}{$line|escape}{if !$line@last}<br>{/if}{/foreach}</div>
{/if}
{if $lbh_ok && $lbh_flash}
    <div class="alert alert-{$lbh_flash.type|escape}">{$lbh_flash.message|escape}</div>
{/if}

<div class="lbh-overview card panel panel-default">
    <div class="card-header panel-heading"><h3 class="card-title panel-title" style="margin:0">{$lbh_lang.overview|escape}</h3></div>
    <div class="card-body panel-body">
    {if !$lbh_ok}
        <p class="text-muted">{$lbh_lang.unavailable|escape}</p>
    {elseif !$lbh_linked}
        <p class="text-muted">{$lbh_lang.not_linked|escape}</p>
    {else}
        <table class="table table-sm table-condensed" style="margin:0">
            <tr><th style="width:40%">{$lbh_lang.product|escape}</th><td>{$lbh_product|escape}</td></tr>
            {if $lbh_domain}<tr><th>{if $lbh_is_server}{$lbh_lang.hostname|escape}{else}{$lbh_lang.domain|escape}{/if}</th><td>{$lbh_domain|escape}</td></tr>{/if}
            {if $lbh_location}<tr><th>{$lbh_lang.location|escape}</th><td>{$lbh_location|escape}</td></tr>{/if}
            {if $lbh_status}<tr><th>{$lbh_lang.status|escape}</th><td><span class="lbh-badge {$lbh_status_class|escape}">{$lbh_status|escape}</span></td></tr>{/if}
            {if $lbh_plan}<tr><th>{$lbh_lang.plan|escape}</th><td>{$lbh_plan|escape}</td></tr>{/if}
            {if $lbh_expires}<tr><th>{$lbh_lang.expires|escape}</th><td>{$lbh_expires|escape}</td></tr>{/if}
            {if $lbh_ip}<tr><th>{$lbh_lang.ip_address|escape}</th><td><code>{$lbh_ip|escape}</code></td></tr>{/if}
            {if $lbh_username}<tr><th>{$lbh_lang.username|escape}</th><td><code>{$lbh_username|escape}</code></td></tr>{/if}
            {if $lbh_is_server && $lbh_os}<tr><th>{$lbh_lang.os|escape}</th><td>{$lbh_os|escape}</td></tr>{/if}
        </table>
    {/if}
    {if $lbh_ok && $lbh_tasks}
        <div class="alert alert-info" style="margin:15px 0 0">
            <strong>{$lbh_lang.pending_title|escape}</strong>
            <ul style="margin:6px 0">
            {foreach $lbh_tasks as $t}
                <li>{$t.item|escape}: {$t.target|escape}</li>
            {/foreach}
            </ul>
            <small>{$lbh_lang.pending_text|escape}</small>
        </div>
    {/if}
    </div>
</div>

{* ---- Quick actions: every button is a POST with this service's token ---- *}
{if $lbh_ok && $lbh_linked && $lbh_show.actions && ($lbh_can.login || $lbh_can.panel || $lbh_can.renew || $lbh_can.refresh)}
<div class="lbh-actions card panel panel-default">
    <div class="card-header panel-heading"><h3 class="card-title panel-title" style="margin:0">{$lbh_lang.actions|escape}</h3></div>
    <div class="card-body panel-body">
        {if $lbh_can.login}
        <form method="post" action="{$lbh_action_url|escape}ClientLogin" target="_blank" rel="noopener">
            <input type="hidden" name="lbh_token" value="{$lbh_action_token|escape}">
            <button type="submit" class="btn btn-primary">{$lbh_panel_label|escape}</button>
        </form>
        {/if}
        {if $lbh_can.panel}
        <form method="post" action="{$lbh_action_url|escape}ClientPanel" target="_blank" data-lbh-panel="1">
            <input type="hidden" name="lbh_token" value="{$lbh_action_token|escape}">
            <button type="submit" class="btn {if $lbh_can.login}btn-default btn-secondary{else}btn-primary{/if}">{$lbh_lang.order_panel|escape}</button>
        </form>
        {/if}
        {if $lbh_can.renew}
        <form method="post" action="{$lbh_action_url|escape}ClientRenew">
            <input type="hidden" name="lbh_token" value="{$lbh_action_token|escape}">
            <button type="submit" class="btn btn-default btn-secondary">{$lbh_lang.renew_now|escape}</button>
        </form>
        {/if}
        {if $lbh_can.refresh}
        <form method="post" action="{$lbh_action_url|escape}ClientRefresh">
            <input type="hidden" name="lbh_token" value="{$lbh_action_token|escape}">
            <button type="submit" class="btn btn-default btn-secondary">{$lbh_lang.refresh|escape}</button>
        </form>
        {/if}
    </div>
</div>
{/if}

{* ---- Server Management Panel (VPS / Dedicated): one-click login to the LogicBoxes server panel, where Start / Stop / Restart are done ---- *}
{if $lbh_ok && $lbh_linked && $lbh_is_server && $lbh_show.power}
<div class="lbh-power card panel panel-default">
    <div class="card-header panel-heading"><h3 class="card-title panel-title" style="margin:0">{$lbh_lang.power_title|escape}</h3></div>
    <div class="card-body panel-body">
        <p>{$lbh_lang.smp_intro|escape}</p>
        {if $lbh_can.panel || $lbh_can.power}
        <div class="lbh-actions">
        {if $lbh_can.panel}
        <form method="post" action="{$lbh_action_url|escape}ClientPanel" target="_blank" data-lbh-panel="1">
            <input type="hidden" name="lbh_token" value="{$lbh_action_token|escape}">
            <button type="submit" class="btn btn-primary">{$lbh_lang.smp_login|escape}</button>
        </form>
        {/if}
        {if $lbh_can.power}
        {foreach $lbh_power_buttons as $act}
        <form method="post" action="{$lbh_action_url|escape}ClientPower" target="_blank" data-lbh-panel="1">
            <input type="hidden" name="lbh_token" value="{$lbh_action_token|escape}">
            <input type="hidden" name="lbh_power" value="{$act|escape}">
            <button type="submit" class="btn btn-default btn-secondary">{$lbh_lang["power_`$act`"]|escape}</button>
        </form>
        {/foreach}
        {/if}
        </div>
        {/if}
        {if $lbh_can.power}<p class="text-muted" style="margin:6px 0 0"><small>{$lbh_lang.power_help|escape}</small></p>{/if}
        <h4 style="margin:18px 0 6px">{$lbh_lang.smp_login_details|escape}</h4>
        {if $lbh_ip || $lbh_username}
        <table class="table table-sm table-condensed" style="margin:0 0 6px">
            {if $lbh_ip}<tr><th style="width:40%">{$lbh_lang.ip_address|escape}</th><td><code>{$lbh_ip|escape}</code></td></tr>{/if}
            {if $lbh_username}<tr><th>{$lbh_lang.username|escape}</th><td><code>{$lbh_username|escape}</code></td></tr>{/if}
        </table>
        {/if}
        <p class="text-muted" style="margin:0"><small>{$lbh_lang.smp_creds_note|escape}</small></p>
    </div>
</div>
{/if}

{* ---- Add-ons, dedicated IPs, block storage ---- *}
{if $lbh_ok && $lbh_extras}
<div class="lbh-extras card panel panel-default">
    <div class="card-header panel-heading"><h3 class="card-title panel-title" style="margin:0">{$lbh_lang.extras_title|escape}</h3></div>
    <div class="card-body panel-body">
        <table class="table table-sm table-condensed" style="margin:0">
        {foreach $lbh_extras as $x}
            <tr><th style="width:40%">{$x.label|escape}</th><td>{$x.value|escape}</td></tr>
        {/foreach}
        </table>
    </div>
</div>
{/if}

{* ---- Name servers and DNS records, checked live ---- *}
{if $lbh_ok && $lbh_dns}
<div class="lbh-dns card panel panel-default">
    <div class="card-header panel-heading"><h3 class="card-title panel-title" style="margin:0">{$lbh_lang.dns_title|escape}</h3></div>
    <div class="card-body panel-body">
        <table class="table table-sm table-condensed">
            <tr><th style="width:40%">{$lbh_lang.dns_ns_current|escape}</th><td>
                {if $lbh_dns.nameservers}{foreach $lbh_dns.nameservers as $n}<code>{$n|escape}</code><br>{/foreach}{else}<span class="text-muted">{$lbh_lang.dns_ns_unknown|escape}</span>{/if}
            </td></tr>
            {if $lbh_dns.target}
            <tr><th>{$lbh_lang.dns_ns_target|escape}</th><td>
                {foreach $lbh_dns.target as $n}<code>{$n|escape}</code><br>{/foreach}
                {if $lbh_dns.ns_ok}<span class="lbh-badge success">{$lbh_lang.dns_ok|escape}</span> {$lbh_lang.dns_ns_ok|escape}{/if}
            </td></tr>
            {/if}
        </table>
        {if $lbh_dns.can_apply}
        <form method="post" action="{$lbh_action_url|escape}ClientNameservers" style="margin-bottom:15px">
            <input type="hidden" name="lbh_token" value="{$lbh_action_token|escape}">
            <button type="submit" class="btn btn-primary">{$lbh_lang.dns_apply|escape}</button>
            <span class="text-muted" style="margin-left:8px">{$lbh_lang.dns_apply_help|escape}</span>
        </form>
        {/if}
        {if $lbh_dns.records}
        <strong>{$lbh_lang.dns_records|escape}</strong>
        <div class="table-responsive">
        <table class="table table-sm table-condensed table-striped" style="margin-top:8px">
            <thead><tr><th>{$lbh_lang.dns_type|escape}</th><th>{$lbh_lang.dns_host|escape}</th><th>{$lbh_lang.dns_expected|escape}</th><th>{$lbh_lang.dns_current|escape}</th><th>{$lbh_lang.dns_state|escape}</th></tr></thead>
            <tbody>
            {foreach $lbh_dns.records as $r}
                <tr>
                    <td>{$r.type|escape}</td>
                    <td><code>{$r.display_host|escape}</code></td>
                    <td><code>{$r.expected|escape}</code></td>
                    <td>{if $r.current}<code>{$r.current|escape}</code>{else}-{/if}</td>
                    <td><span class="lbh-badge {$r.state_class|escape}">{$r.state_label|escape}</span></td>
                </tr>
            {/foreach}
            </tbody>
        </table>
        </div>
        <small class="text-muted">{if $lbh_dns.all_ok}{$lbh_lang.dns_all_ok|escape} {/if}{$lbh_dns.checked_text|escape}</small>
        {/if}
    </div>
</div>
{/if}

{* ---- Password and SSH ---- *}
{if $lbh_ok && $lbh_linked && ($lbh_can.password || $lbh_can.shell)}
<div class="lbh-security card panel panel-default">
    <div class="card-header panel-heading"><h3 class="card-title panel-title" style="margin:0">{$lbh_lang.security_title|escape}</h3></div>
    <div class="card-body panel-body">
        {if $lbh_can.password}
        <form method="post" action="{$lbh_action_url|escape}ClientPassword" autocomplete="off" style="max-width:420px">
            <input type="hidden" name="lbh_token" value="{$lbh_action_token|escape}">
            <strong>{$lbh_lang.password_title|escape}</strong>
            <div class="form-group" style="margin-top:8px">
                <label for="lbh_password">{$lbh_lang.password_new|escape}</label>
                <input type="password" class="form-control" id="lbh_password" name="lbh_password" minlength="9" maxlength="16" autocomplete="new-password" required>
            </div>
            <div class="form-group">
                <label for="lbh_password2">{$lbh_lang.password_repeat|escape}</label>
                <input type="password" class="form-control" id="lbh_password2" name="lbh_password2" minlength="9" maxlength="16" autocomplete="new-password" required>
            </div>
            <p class="text-muted"><small>{$lbh_lang.password_rules|escape}</small></p>
            <button type="submit" class="btn btn-default btn-secondary">{$lbh_lang.password_save|escape}</button>
        </form>
        {/if}
        {if $lbh_can.shell}
        <div{if $lbh_can.password} style="margin-top:20px"{/if}>
            <strong>{$lbh_lang.shell_title|escape}</strong>
            <p class="text-muted" style="margin:6px 0">{$lbh_lang.shell_text|escape}</p>
            <form method="post" action="{$lbh_action_url|escape}ClientShell" style="display:inline">
                <input type="hidden" name="lbh_token" value="{$lbh_action_token|escape}">
                <input type="hidden" name="lbh_enable" value="1">
                <button type="submit" class="btn btn-default btn-secondary">{$lbh_lang.shell_enable|escape}</button>
            </form>
            <form method="post" action="{$lbh_action_url|escape}ClientShell" style="display:inline">
                <input type="hidden" name="lbh_token" value="{$lbh_action_token|escape}">
                <input type="hidden" name="lbh_enable" value="0">
                <button type="submit" class="btn btn-default btn-secondary">{$lbh_lang.shell_disable|escape}</button>
            </form>
        </div>
        {/if}
    </div>
</div>
{/if}

{* ---- Plan / add-on changes, priced the way the provider charges (Phase 4) ---- *}
{if $lbh_ok && ($lbh_notice || $lbh_error)}
    <div class="alert {if $lbh_error}alert-danger{else}alert-success{/if}">{if $lbh_error}{$lbh_error|escape}{else}{$lbh_notice|escape}{/if}</div>
{/if}
{if $lbh_ok && $lbh_upgrade && $lbh_show.changes}
<div class="lbh-upgrade card panel panel-default" style="margin-bottom:20px">
    <div class="card-header panel-heading"><h3 class="card-title panel-title" style="margin:0">{$lbh_lang.upgrade_title|escape}</h3></div>
    <div class="card-body panel-body">
    {if $lbh_upgrade.pending}
        <div class="alert alert-info" style="margin:0">
            <strong>{$lbh_lang.upgrade_pending|escape}:</strong> {$lbh_upgrade.pending.summary|escape}<br>
            {if $lbh_upgrade.pending.invoice_id}
                <a class="btn btn-primary btn-sm" style="margin-top:8px" href="viewinvoice.php?id={$lbh_upgrade.pending.invoice_id|escape}">{$lbh_lang.upgrade_pay_invoice|escape}</a>
            {else}
                {$lbh_lang.upgrade_processing|escape}
            {/if}
        </div>
    {elseif $lbh_quote}
        <h4 style="margin-top:0">{$lbh_lang.upgrade_quote_title|escape}</h4>
        <ul>
        {foreach $lbh_quote.lines as $line}
            <li>{$line|escape}</li>
        {/foreach}
        </ul>
        <p>
            <strong>{if $lbh_quote.free}{$lbh_lang.upgrade_nothing_due|escape}{else}{$lbh_lang.upgrade_due_now|escape}: {$lbh_quote.amount|escape} {$lbh_quote.currency|escape}{/if}</strong>
            {if !$lbh_quote.free}<span class="text-muted">({$lbh_quote.months_text|escape})</span>{/if}
            {if $lbh_quote.recurring}<br>{$lbh_lang.upgrade_new_recurring|escape}: {$lbh_quote.recurring|escape} {$lbh_quote.currency|escape}{/if}
        </p>
        <form method="post" action="{$lbh_form_url|escape}" style="display:inline">
            <input type="hidden" name="lbh_token" value="{$lbh_token|escape}">
            <input type="hidden" name="lbh_action" value="upgrade_confirm">
            <input type="hidden" name="lbh_kind" value="{$lbh_quote.kind|escape}">
            {if $lbh_quote.kind eq 'plan'}
                <input type="hidden" name="lbh_product" value="{$lbh_quote.product_id|escape}">
            {else}
                {foreach $lbh_quote.options as $cid => $val}
                    <input type="hidden" name="lbh_opt[{$cid|escape}]" value="{$val|escape}">
                {/foreach}
            {/if}
            <button type="submit" class="btn btn-primary">{if $lbh_quote.free}{$lbh_lang.upgrade_confirm_free|escape}{else}{$lbh_lang.upgrade_confirm_pay|escape}{/if}</button>
        </form>
        <a class="btn btn-default btn-secondary" href="{$lbh_form_url|escape}">{$lbh_lang.upgrade_cancel|escape}</a>
    {else}
        <p class="text-muted">{$lbh_lang.upgrade_intro|escape}</p>
        {if $lbh_upgrade.plans}
        <form method="post" action="{$lbh_form_url|escape}" class="form-inline" style="margin-bottom:15px">
            <input type="hidden" name="lbh_token" value="{$lbh_token|escape}">
            <input type="hidden" name="lbh_action" value="upgrade_quote">
            <input type="hidden" name="lbh_kind" value="plan">
            <label for="lbh_product"><strong>{$lbh_lang.upgrade_plan|escape}</strong></label>
            <select name="lbh_product" id="lbh_product" class="form-control" style="margin:0 8px" required>
                <option value="">{$lbh_lang.upgrade_plan_pick|escape}</option>
                {foreach $lbh_upgrade.plans as $p}
                    <option value="{$p.product_id|escape}">{$p.name|escape} - {$p.price|escape} {$lbh_upgrade.currency|escape} {$lbh_lang.upgrade_per_cycle|escape}</option>
                {/foreach}
            </select>
            <button type="submit" class="btn btn-default btn-secondary">{$lbh_lang.upgrade_get_price|escape}</button>
        </form>
        {/if}
        {if $lbh_upgrade.options}
        <form method="post" action="{$lbh_form_url|escape}">
            <input type="hidden" name="lbh_token" value="{$lbh_token|escape}">
            <input type="hidden" name="lbh_action" value="upgrade_quote">
            <input type="hidden" name="lbh_kind" value="options">
            <strong>{$lbh_lang.upgrade_options|escape}</strong>
            <table class="table table-sm table-condensed" style="margin-top:8px">
            {foreach $lbh_upgrade.options as $o}
                <tr>
                    <th style="width:40%"><label for="lbh_opt_{$o.config_id|escape}" style="margin:0">{$o.name|escape}</label></th>
                    <td>
                    {if $o.type eq 1}
                        <select name="lbh_opt[{$o.config_id|escape}]" id="lbh_opt_{$o.config_id|escape}" class="form-control" style="max-width:320px">
                            {foreach $o.subs as $s}
                                <option value="{$s.sub_id|escape}"{if $s.sub_id eq $o.current} selected{/if}>{$s.label|escape}</option>
                            {/foreach}
                        </select>
                    {elseif $o.type eq 3}
                        <select name="lbh_opt[{$o.config_id|escape}]" id="lbh_opt_{$o.config_id|escape}" class="form-control" style="max-width:120px">
                            <option value="0"{if !$o.current} selected{/if}>{$lbh_lang.no|escape}</option>
                            <option value="1"{if $o.current} selected{/if}>{$lbh_lang.yes|escape}</option>
                        </select>
                    {else}
                        <input type="number" name="lbh_opt[{$o.config_id|escape}]" id="lbh_opt_{$o.config_id|escape}" class="form-control" style="max-width:120px" min="0"{if $o.max} max="{$o.max|escape}"{/if} value="{$o.current|escape}">
                    {/if}
                    </td>
                </tr>
            {/foreach}
            </table>
            <button type="submit" class="btn btn-default btn-secondary">{$lbh_lang.upgrade_get_price|escape}</button>
        </form>
        {/if}
    {/if}
    </div>
</div>
{/if}
</div>
{* Panel logins: the tab this page opens signs in to LogicBoxes, then is moved to the order's own page *}
<script>
{literal}
(function () {
    var wins = {};
    window.lbhPanelPage = function (name, page, delay) {
        var w = wins[name];
        if (!w || !/^https:\/\//.test(page)) { return; }
        setTimeout(function () { try { if (!w.closed) { w.location.href = page; } } catch (e) {} }, delay || 6000);
    };
    document.addEventListener('submit', function (ev) {
        var f = ev.target;
        if (!f || !f.getAttribute || !f.getAttribute('data-lbh-panel')) { return; }
        var name = 'lbhpanel' + new Date().getTime();
        var w = window.open('about:blank', name);
        if (w) { wins[name] = w; f.target = name; }
    }, true);
})();
{/literal}
</script>
