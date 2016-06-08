<?php

require_once __DIR__.'/_config.php';

$idps = IdpConfig::current()->getBuildContainer()->getPartyContainer()->getSpEntityDescriptorStore()->all();
switch (count($idps)) {
    case 0:
        print "None SP configured";
        exit;
//    case 1:
//        header('Location: initiate.php?sp='.$all[0]->getEntityID());
//        exit;
}

print "<h1>Following SPs are configured</h1>\n";
print "<p><small>Choose one for IDP initiated SSO</small></p>\n";
foreach ($idps as $idp) {
    if ($idp->getAllSpSsoDescriptors()) {
        print "<p><a href=\"initiate.php?sp={$idp->getEntityID()}\">{$idp->getEntityID()}</a></p>\n";
    }
}
