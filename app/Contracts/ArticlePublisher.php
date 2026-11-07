<?php

namespace App\Contracts;

use App\Models\Article;

interface ArticlePublisher
{
    public function create(Article $article): array;

    public function update(Article $article): array;

    public function delete(Article $article): array;

    public function syncStatus(Article $article): array;
}
