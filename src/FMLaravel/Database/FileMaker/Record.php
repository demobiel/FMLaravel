<?php namespace FMLaravel\Database\FileMaker;

use airmoi\FileMaker\Object\Record as Filemaker_Record;
use FMLaravel\Database\FileMaker\RecordInterface;
use FMLaravel\Database\FileMaker\RecordImplementation;

class Record extends Filemaker_Record implements RecordInterface
{

    public function __construct($layout)
    {
       $this->layout = $layout;
       $this->fm = $layout->fm;

    }

    public function getAllFields()
    {
        $fields = $this->getFields();
        $temp = array();
        for ($x = 0; $x < count($this->getFields()); $x++) {
            $field = $fields[$x];
            $temp[$field] = $this->getField($field);
        }
        return $temp;


    }
}
