<?php


namespace Sajya\Lucene;

use Sajya\Lucene\Exception\InvalidArgumentException;
use Sajya\Lucene\Exception\RuntimeException;

/**
 * Abstract Finite State Machine
 *
 * Take a look on Wikipedia state machine description: http://en.wikipedia.org/wiki/Finite_state_machine
 *
 * Any type of Transducers (Moore machine or Mealy machine) also may be implemented by using this abstract FSM.
 * process() methods invokes a specified actions which may construct FSM output.
 * Actions may be also used to signal, that we have reached Accept State
 *
 * @category   Zend
 * @package    Zend_Search_Lucene
 */
abstract class AbstractFSM
{
    /**
     * Machine States alphabet
     *
     * @var array
     */
    private $_states = [];

    /**
     * Current state
     *
     * @var integer|string
     */
    private $_currentState = null;

    /**
     * Input alphabet
     *
     * @var array
     */
    private $inputAphabet = [];

    /**
     * State transition table
     *
     * [sourceState][input] => targetState
     *
     * @var array
     */
    private $_rules = [];

    /**
     * List of entry actions
     * Each action executes when entering the state
     *
     * [state] => action
     *
     * @var array
     */
    private $_entryActions = [];

    /**
     * List of exit actions
     * Each action executes when exiting the state
     *
     * [state] => action
     *
     * @var array
     */
    private $_exitActions = [];

    /**
     * List of input actions
     * Each action executes when entering the state
     *
     * [state][input] => action
     *
     * @var array
     */
    private $inputActions = [];

    /**
     * List of input actions
     * Each action executes when entering the state
     *
     * [state1][state2] => action
     *
     * @var array
     */
    private $_transitionActions = [];

    /**
     * Finite State machine constructor
     *
     * $states is an array of integers or strings with a list of possible machine states
     * constructor treats fist list element as a sturt state (assignes it to $_current state).
     * It may be reassigned by setState() call.
     * States list may be empty and can be extended later by addState() or addStates() calls.
     *
     * $inputAphabet is the same as $states, but represents input alphabet
     * it also may be extended later by addInputSymbols() or addInputSymbol() calls.
     *
     * $rules parameter describes FSM transitions and has a structure:
     * array( array(sourseState, input, targetState[, inputAction]),
     *        array(sourseState, input, targetState[, inputAction]),
     *        array(sourseState, input, targetState[, inputAction]),
     *        ...
     *      )
     * Rules also can be added later by addRules() and addRule() calls.
     *
     * FSM actions are very flexible and may be defined by addEntryAction(), addExitAction(),
     * addInputAction() and addTransitionAction() calls.
     *
     * @param array $states
     * @param array $inputAphabet
     * @param array $rules
     */
    public function __construct($states = [], $inputAphabet = [], $rules = [])
    {
        $this->addStates($states);
        $this->addInputSymbols($inputAphabet);
        $this->addRules($rules);
    }

    /**
     * Add states to the state machine
     *
     * @param array $states
     */
    public function addStates($states): void
    {
        foreach ($states as $state) {
            $this->addState($state);
        }
    }

    /**
     * Add state to the state machine
     *
     * @param integer|string $state
     */
    public function addState($state): void
    {
        $this->_states[$state] = $state;

        if ($this->_currentState === null) {
            $this->_currentState = $state;
        }
    }

    /**
     * Add symbols to the input alphabet
     *
     * @param array $inputAphabet
     */
    public function addInputSymbols($inputAphabet): void
    {
        foreach ($inputAphabet as $inputSymbol) {
            $this->addInputSymbol($inputSymbol);
        }
    }

    /**
     * Add symbol to the input alphabet
     *
     * @param integer|string $inputSymbol
     */
    public function addInputSymbol($inputSymbol): void
    {
        $this->inputAphabet[$inputSymbol] = $inputSymbol;
    }

    /**
     * Add transition rules
     *
     * array structure:
     * array( array(sourseState, input, targetState[, inputAction]),
     *        array(sourseState, input, targetState[, inputAction]),
     *        array(sourseState, input, targetState[, inputAction]),
     *        ...
     *      )
     *
     * @param array $rules
     */
    public function addRules($rules): void
    {
        foreach ($rules as $rule) {
            $this->addrule($rule[0], $rule[1], $rule[2], $rule[3] ?? null);
        }
    }

    /**
     * Add symbol to the input alphabet
     *
     * @param integer|string $sourceState
     * @param integer|string $input
     * @param integer|string $targetState
     * @param FSMAction|null $inputAction
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function addRule($sourceState, $input, $targetState, $inputAction = null): void
    {
        if (!isset($this->_states[$sourceState])) {
            throw new Exception\InvalidArgumentException('Undefined source state (' . $sourceState . ').');
        }
        if (!isset($this->_states[$targetState])) {
            throw new Exception\InvalidArgumentException('Undefined target state (' . $targetState . ').');
        }
        if (!isset($this->inputAphabet[$input])) {
            throw new Exception\InvalidArgumentException('Undefined input symbol (' . $input . ').');
        }

        if (!isset($this->_rules[$sourceState])) {
            $this->_rules[$sourceState] = [];
        }
        if (isset($this->_rules[$sourceState][$input])) {
            throw new Exception\RuntimeException('Rule for {state,input} pair (' . $sourceState . ', ' . $input . ') is already defined.');
        }

        $this->_rules[$sourceState][$input] = $targetState;


        if ($inputAction !== null) {
            $this->addInputAction($sourceState, $input, $inputAction);
        }
    }

    /**
     * Add input action (defined by {state, input} pair).
     * Several input actions are allowed.
     * Action execution order is defined by addInputAction() calls
     *
     * @param integer|string $state
     * @param integer|string $input
     * @param FSMAction      $action
     *
     * @throws InvalidArgumentException
     */
    public function addInputAction($state, $inputSymbol, FSMAction $action): void
    {
        if (!isset($this->_states[$state])) {
            throw new Exception\InvalidArgumentException('Undefined state (' . $state . ').');
        }
        if (!isset($this->inputAphabet[$inputSymbol])) {
            throw new Exception\InvalidArgumentException('Undefined input symbol (' . $inputSymbol . ').');
        }

        if (!isset($this->inputActions[$state])) {
            $this->inputActions[$state] = [];
        }
        if (!isset($this->inputActions[$state][$inputSymbol])) {
            $this->inputActions[$state][$inputSymbol] = [];
        }

        $this->inputActions[$state][$inputSymbol][] = $action;
    }

    /**
     * Set FSM state.
     * No any action is invoked
     *
     * @param integer|string $state
     *
     * @throws InvalidArgumentException
     */
    public function setState($state): void
    {
        if (!isset($this->_states[$state])) {
            throw new Exception\InvalidArgumentException('State \'' . $state . '\' is not on of the possible FSM states.');
        }

        $this->_currentState = $state;
    }

    /**
     * Get FSM state.
     *
     * @return integer|string $state|null
     */
    public function getState()
    {
        return $this->_currentState;
    }

    /**
     * Add state entry action.
     * Several entry actions are allowed.
     * Action execution order is defined by addEntryAction() calls
     *
     * @param integer|string $state
     * @param FSMAction      $action
     *
     * @throws InvalidArgumentException
     */
    public function addEntryAction($state, FSMAction $action): void
    {
        if (!isset($this->_states[$state])) {
            throw new Exception\InvalidArgumentException('Undefined state (' . $state . ').');
        }

        if (!isset($this->_entryActions[$state])) {
            $this->_entryActions[$state] = [];
        }

        $this->_entryActions[$state][] = $action;
    }

    /**
     * Add state exit action.
     * Several exit actions are allowed.
     * Action execution order is defined by addEntryAction() calls
     *
     * @param integer|string $state
     * @param FSMAction      $action
     *
     * @throws InvalidArgumentException
     */
    public function addExitAction($state, FSMAction $action): void
    {
        if (!isset($this->_states[$state])) {
            throw new Exception\InvalidArgumentException('Undefined state (' . $state . ').');
        }

        if (!isset($this->_exitActions[$state])) {
            $this->_exitActions[$state] = [];
        }

        $this->_exitActions[$state][] = $action;
    }

    /**
     * Add transition action (defined by {state, input} pair).
     * Several transition actions are allowed.
     * Action execution order is defined by addTransitionAction() calls
     *
     * @param integer|string $sourceState
     * @param integer|string $targetState
     * @param FSMAction      $action
     *
     * @throws InvalidArgumentException
     */
    public function addTransitionAction($sourceState, $targetState, FSMAction $action): void
    {
        if (!isset($this->_states[$sourceState])) {
            throw new Exception\InvalidArgumentException('Undefined source state (' . $sourceState . ').');
        }
        if (!isset($this->_states[$targetState])) {
            throw new Exception\InvalidArgumentException('Undefined source state (' . $targetState . ').');
        }

        if (!isset($this->_transitionActions[$sourceState])) {
            $this->_transitionActions[$sourceState] = [];
        }
        if (!isset($this->_transitionActions[$sourceState][$targetState])) {
            $this->_transitionActions[$sourceState][$targetState] = [];
        }

        $this->_transitionActions[$sourceState][$targetState][] = $action;
    }


    /**
     * Process an input
     *
     * @param mixed $input
     *
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    public function process($input): void
    {
        if (!isset($this->_rules[$this->_currentState])) {
            throw new Exception\RuntimeException('There is no any rule for current state (' . $this->_currentState . ').');
        }
        if (!isset($this->_rules[$this->_currentState][$input])) {
            throw new Exception\InvalidArgumentException('There is no any rule for {current state, input} pair (' . $this->_currentState . ', ' . $input . ').');
        }

        $sourceState = $this->_currentState;
        $targetState = $this->_rules[$this->_currentState][$input];

        if ($sourceState != $targetState && isset($this->_exitActions[$sourceState])) {
            foreach ($this->_exitActions[$sourceState] as $action) {
                $action->doAction();
            }
        }
        if (isset($this->inputActions[$sourceState][$input])) {
            foreach ($this->inputActions[$sourceState][$input] as $action) {
                $action->doAction();
            }
        }


        $this->_currentState = $targetState;

        if (isset($this->_transitionActions[$sourceState][$targetState])) {
            foreach ($this->_transitionActions[$sourceState][$targetState] as $action) {
                $action->doAction();
            }
        }
        if ($sourceState != $targetState && isset($this->_entryActions[$targetState])) {
            foreach ($this->_entryActions[$targetState] as $action) {
                $action->doAction();
            }
        }
    }

    /**
     * @throws RuntimeException
     */
    public function reset(): void
    {
        if (count($this->_states) == 0) {
            throw new Exception\RuntimeException('There is no any state defined for FSM.');
        }

        $this->_currentState = $this->_states[0];
    }
}
