<?php
namespace App\Interfaces;

interface LLMCall{
   public function createData(string $prompt):array;
}
