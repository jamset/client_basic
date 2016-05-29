<?php
/**
 * Created by PhpStorm.
 * User: ww
 * Date: 28.10.15
 * Time: 14:47
 */
namespace FractalBasic\Client\Interfaces;

interface Client
{

    /**Initialize Gearman tasks
     * @return mixed
     */
    public function initTasks($repeatedly = null);


}
