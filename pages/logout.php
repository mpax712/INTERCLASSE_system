<?php
logout_user();
flash('Você saiu do sistema.');
redirect('index.php');
