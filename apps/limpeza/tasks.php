<?php

function helloWorld(): void
{
    echo "Hello World!\n";
}

function limparTemporarios(): void
{
    $dir = sys_get_temp_dir(); //diretorio /tmp
    $removidos = 0;

    $arquivos = glob($dir . '/autotask_*');

    if ($arquivos === false) {
        $arquivos = [];
    }

    foreach ($arquivos as $caminho) {
        if (is_file($caminho) && !is_link($caminho)) {
            if (@unlink($caminho)) {
                $removidos++;
            }
        }
    }

    echo "Arquivos temporários removidos: $removidos\n";
}

function tarefaComErro(): void
{
    throw new RuntimeException('Erro simulado para testar retentativas');
}

