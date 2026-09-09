<?php
declare(strict_types=1);
require dirname(__DIR__).'/.cms/source/bootstrap.php';
// Public RFC 8291 Appendix A test vector; no live installation credentials.
$decode=App\Core\WebPushKeyStore::decode(...);
$private=$decode('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
$public=$decode('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
$der=hex2bin('30770201010420').$private.hex2bin('a00a06082a8648ce3d030107a144034200').$public;
$key=openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END EC PRIVATE KEY-----\n");
if(!$key)throw new RuntimeException('Cannot load RFC fixture');
$wire=(new App\Core\WebPushCrypto())->encrypt('When I grow up, I want to be a watermelon','BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4','BTBZMqHH6r4Tts7J_aSIgg',$key,$decode('DGv6ra1nlYgDCS1FRnbzlw'));
$expected=$decode('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8').$decode('8pfeW0KbunFT06SuDKoJH9Ql87S1QUrdirN6GcG7sFz1y1sqLgVi1VhjVkHsUoEsbI_0LpXMuGvnzQ');
if(!hash_equals($expected,$wire))throw new RuntimeException('RFC 8291 encrypted wire payload differs');
echo "PASS RFC 8291: ECDH, HKDF, AES-GCM, header and ciphertext match official vector.\n";
