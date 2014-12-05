<?php
namespace ColourStream\Bundle\CronBundle\Command;

use Doctrine\ORM\EntityManager;
use Symfony\Component\Console\Input\ArgvInput;
use ColourStream\Bundle\CronBundle\Entity\CronJobResult;
use ColourStream\Bundle\CronBundle\Entity\CronJob;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

class CronRunCommand extends ContainerAwareCommand
{
    const RESULT_MIN = 0;
    const SUCCEEDED = 0;
    const FAILED = 1;
    const SKIPPED = 2;
    const RESULT_MAX = 2;

    protected function configure()
    {
        $this->setName("cron:run")
             ->setDescription("Runs any currently schedule cron jobs")
             ->addArgument("job", InputArgument::OPTIONAL, "Run only this job (if enabled)");
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $start = microtime(true);
        $em = $this->getContainer()->get("doctrine.orm.entity_manager");
        $jobRepo = $em->getRepository('ColourStreamCronBundle:CronJob');

        $jobsToRun = array();
        if($jobName = $input->getArgument('job'))
        {
            try
            {
                $jobObj = $jobRepo->findOneByCommand($jobName);
                if($jobObj->getEnabled())
                {
                    $jobsToRun = array($jobObj);
                }
            }
            catch(\Exception $e)
            {
                $output->writeln("Couldn't find a job by the name of $jobName");
                return self::FAILED;
            }
        }
        else
        {
            $jobsToRun = $jobRepo->findDueTasks();
        }

        $jobCount = count($jobsToRun);
        $output->writeln("Running $jobCount jobs:");
        
        foreach($jobsToRun as $job)
        {
            $this->runJob($job, $output, $em);
        }

        $end = microtime(true);
        $duration = sprintf("%0.2f", $end-$start);
        $output->writeln("Cron run completed in $duration seconds");
    }
    
    protected function runJob(CronJob $job, OutputInterface $output, EntityManager $em)
    {
        $output->write("Running " . $job->getCommand() . ": ");
        
        try
        {
            $commandToRun = $this->getApplication()->get($job->getCommand());
        }
        catch(\InvalidArgumentException $ex)
        {
            $output->writeln(" skipped (command no longer exists)");

            // No need to reschedule non-existant commands
            return;
        }
        
        $emptyInput = new ArgvInput();
        $jobOutput = new MemoryWriter();
        
        $jobStart = microtime(true);
        try
        {
            $returnCode = $commandToRun->execute($emptyInput, $jobOutput);
        }
        catch(\Exception $ex)
        {
            $returnCode = self::FAILED;
            $jobOutput->writeln("");
            $jobOutput->writeln("Job execution failed with exception " . get_class($ex) . ":");
            $jobOutput->writeln($ex->__toString());
        }
        $jobEnd = microtime(true);

        // Clamp the result to accepted values
        if (is_null($returnCode)) $returnCode = self::SUCCEEDED;

        if($returnCode < self::RESULT_MIN || $returnCode > self::RESULT_MAX)
        {
            $returnCode = self::FAILED;
        }

        // Output the result
        $statusStr = "unknown";
        if($returnCode == self::SKIPPED)
        {
            $statusStr = "skipped";
        }
        elseif($returnCode == self::SUCCEEDED)
        {
            $statusStr = "succeeded";
        }
        elseif($returnCode == self::FAILED)
        {
            $statusStr = "failed";
        }
        
        $durationStr = sprintf("%0.2f", $jobEnd-$jobStart);
        $output->writeln("$statusStr in $durationStr seconds");
        
        // And update the job with it's next scheduled time
        $newTime = new \DateTime();
        $newTime = $newTime->add(new \DateInterval($job->getInterval()));
        $job->setNextRun($newTime);
    }

}
