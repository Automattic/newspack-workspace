<?php

$dirHandle = opendir('.');
    while($file = readdir($dirHandle)){
    if(is_dir($file) && $file != '.' && $file != '..'){
        ?>
        <h2><?php echo $file; ?></h2>
        <p>
            <a href="http://additional-sites.local/<?php echo $file; ?>">Site</a>
            |
            <a href="http://additional-sites.local/<?php echo $file; ?>/wp-admin">WP Admin</a>
            
        </p>
        <?php
    }
} 