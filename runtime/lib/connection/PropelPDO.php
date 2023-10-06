<?php

if (version_compare(PHP_VERSION, '8.0.0') >= 0) {
    /**
     *  * PDO connection subclass that provides the basic fixes to PDO that are required by Propel.
     *
     * This class was designed to work around the limitation in PDO where attempting to begin
     * a transaction when one has already been begun will trigger a PDOException.  Propel
     * relies on the ability to create nested transactions, even if the underlying layer
     * simply ignores these (because it doesn't support nested transactions).
     *
     * The changes that this class makes to the underlying API include the addition of the
     * getNestedTransactionDepth() and isInTransaction() and the fact that beginTransaction()
     * will no longer throw a PDOException (or trigger an error) if a transaction is already
     * in-progress.
     */
    class PropelPDO extends BasePropelPDO
    {
        #[ReturnTypeWillChange]
        public function prepare(string $query, array $options = [])
        {
            return parent::prepareStatement($query, $options);
        }

        #[ReturnTypeWillChange]
        public function query($statement, $mode = PDO::FETCH_NUM, ...$fetch_mode_args)
        {
            return parent::executeQuery(...func_get_args());
        }
    }
} else {
    /**
     *  * PDO connection subclass that provides the basic fixes to PDO that are required by Propel.
     *
     * This class was designed to work around the limitation in PDO where attempting to begin
     * a transaction when one has already been begun will trigger a PDOException.  Propel
     * relies on the ability to create nested transactions, even if the underlying layer
     * simply ignores these (because it doesn't support nested transactions).
     *
     * The changes that this class makes to the underlying API include the addition of the
     * getNestedTransactionDepth() and isInTransaction() and the fact that beginTransaction()
     * will no longer throw a PDOException (or trigger an error) if a transaction is already
     * in-progress.
     */
    class PropelPDO extends BasePropelPDO
    {
        public function prepare($query, $options = null)
        {
            return parent::prepareStatement($query, $options ?? []);
        }

        public function query($statement, $mode = PDO::FETCH_NUM, $arg3 = null, array $ctorargs = [])
        {
            return parent::executeQuery(...func_get_args());
        }
    }
}
