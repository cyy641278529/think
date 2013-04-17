<?php
// +----------------------------------------------------------------------
// | TOPThink [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2011 http://topthink.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace Traits\Think;

trait Extend {
    
    protected $partition    =   [];

    /**
     * 字段值延迟增长
     * @access public
     * @param string $field  字段名
     * @param integer $step  增长值
     * @param integer $lazyTime  延时时间(s)
     * @return boolean
     */
    public function setLazyInc($field,$step=1,$lazyTime=0) {
        $condition   =  $this->options['where'];
        if(empty($condition)) { // 没有条件不做任何更新
            return false;
        }
        if($lazyTime>0) {// 延迟写入
            $guid =  md5($this->name.'_'.$field.'_'.serialize($condition));
            $step = $this->lazyWrite($guid,$step,$lazyTime);
            if(false === $step ) return true; // 等待下次写入
        }
        return $this->setField($field,array('exp',$field.'+'.$step));
    }

    /**
     * 字段值延迟减少
     * @access public
     * @param string $field  字段名
     * @param integer $step  减少值
     * @param integer $lazyTime  延时时间(s)
     * @return boolean
     */
    public function setLazyDec($field,$step=1,$lazyTime=0) {
        $condition   =  $this->options['where'];
        if(empty($condition)) { // 没有条件不做任何更新
            return false;
        }
        if($lazyTime>0) {// 延迟写入
            $guid =  md5($this->name.'_'.$field.'_'.serialize($condition));
            $step = $this->lazyWrite($guid,$step,$lazyTime);
            if(false === $step ) return true; // 等待下次写入
        }
        return $this->setField($field,array('exp',$field.'-'.$step));
    }

    /**
     * 延时更新检查 返回false表示需要延时
     * 否则返回实际写入的数值
     * @access public
     * @param string $guid  写入标识
     * @param integer $step  写入步进值
     * @param integer $lazyTime  延时时间(s)
     * @return false|integer
     */
    protected function lazyWrite($guid,$step,$lazyTime) {
        if(false !== ($value = F($guid))) { // 存在缓存写入数据
            if(time()>S($guid.'_time')+$lazyTime) {
                // 延时更新时间到了，删除缓存数据 并实际写入数据库
                S($guid,NULL);
                S($guid.'_time',NULL);
                return $value+$step;
            }else{
                // 追加数据到缓存
                S($guid,$value+$step);
                return false;
            }
        }else{ // 没有缓存数据
            S($guid,$step);
            // 计时开始
            S($guid.'_time',time());
            return false;
        }
    }

    /**
     * 批处理执行SQL语句
     * 批处理的指令都认为是execute操作
     * @access public
     * @param array $sql  SQL批处理指令
     * @return boolean
     */
    public function patchQuery($sql=[]) {
        if(!is_array($sql)) return false;
        // 自动启动事务支持
        $this->startTrans();
        try{
            foreach ($sql as $_sql){
                $result   =  $this->execute($_sql);
                if(false === $result) {
                    // 发生错误自动回滚事务
                    $this->rollback();
                    return false;
                }
            }
            // 提交事务
            $this->commit();
        } catch (\Think\Exception $e) {
            $this->rollback();
        }
        return true;
    }

    /**
     * 得到分表的的数据表名
     * @access public
     * @param array $data 操作的数据
     * @return string
     */
    public function getPartitionTableName($data=[]) {
        // 对数据表进行分区
        if(isset($data[$this->partition['field']])) {
            $field   =   $data[$this->partition['field']];
            switch($this->partition['type']) {
                case 'id':
                    // 按照id范围分表
                    $step    =   $this->partition['expr'];
                    $seq    =   floor($field / $step)+1;
                    break;
                case 'year':
                    // 按照年份分表
                    if(!is_numeric($field)) {
                        $field   =   strtotime($field);
                    }
                    $seq    =   date('Y',$field)-$this->partition['expr']+1;
                    break;
                case 'mod':
                    // 按照id的模数分表
                    $seq    =   ($field % $this->partition['num'])+1;
                    break;
                case 'md5':
                    // 按照md5的序列分表
                    $seq    =   (ord(substr(md5($field),0,1)) % $this->partition['num'])+1;
                    break;
                default :
                    if(function_exists($this->partition['type'])) {
                        // 支持指定函数哈希
                        $fun    =   $this->partition['type'];
                        $seq    =   (ord(substr($fun($field),0,1)) % $this->partition['num'])+1;
                    }else{
                        // 按照字段的首字母的值分表
                        $seq    =   (ord($field{0}) % $this->partition['num'])+1;
                    }
            }
            return $this->getTableName().'_'.$seq;
        }else{
            // 当设置的分表字段不在查询条件或者数据中
            // 进行联合查询，必须设定 partition['num']
            $tableName  =   [];
            for($i=0;$i<$this->partition['num'];$i++)
                $tableName[] = 'SELECT * FROM '.$this->getTableName().'_'.($i+1);
            $tableName = '( '.implode(" UNION ",$tableName).') AS '.$this->name;
            return $tableName;
        }
    }
}