<?php
$interfaceCopy = \App\Core\ConsoleLocale::catalogue();
if (!isset($screen)) {
    $interfaceCopy = array_intersect_key($interfaceCopy, array_flip([
        'Close','Success','Welcome','Warning','Error','License notice','Information','Cancel','Confirm',
        'Action completed.','The action could not be completed.','Save unconfirmed','Unsaved','Unsaved changes',
    ]));
}
?>
<script nonce="<?= htmlspecialchars((string) ($_SERVER['SENSE_CSP_NONCE'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
(() => {
    const catalogue = <?= json_encode((object) $interfaceCopy, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
    window.SenseCMSInterfaceText = (source, values = {}) => {
        const text = Object.hasOwn(catalogue, source) ? catalogue[source] : source;
        return text.replace(/\{\w+\}/g, token => Object.hasOwn(values, token) ? values[token] : token);
    };
    window.SenseCMSInterfaceHtml = source => window.SenseCMSInterfaceText(source).replace(/[&<>"']/g,
        char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[char]);
})();
</script>
