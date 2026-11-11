<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:admin:reset-password',
    description: 'Reset an administrator password using a secure password prompt',
)]
final class ResetAdminPasswordCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email address of the administrator account');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->users->findOneBy(['email' => $input->getArgument('email')]);
        if (!$user instanceof User || $user->getRole() !== 'admin' || !$user->isActive()) {
            $output->writeln('<error>No active administrator account was found for that email.</error>');

            return Command::FAILURE;
        }

        $helper = $this->getHelper('question');
        $passwordQuestion = new Question('New admin password: ');
        $passwordQuestion->setHidden(true);
        $passwordQuestion->setHiddenFallback(false);
        $passwordQuestion->setTrimmable(false);
        $password = $helper->ask($input, $output, $passwordQuestion);

        if (!is_string($password)) {
            $output->writeln('<error>Please enter a password.</error>');

            return Command::FAILURE;
        }

        $passwordError = \App\Security\CredentialSecurityPolicy::validatePassword($password, $user->getEmail());
        if ($passwordError !== null) {
            $output->writeln(sprintf('<error>%s</error>', $passwordError));

            return Command::FAILURE;
        }

        $confirmationQuestion = new Question('Confirm new admin password: ');
        $confirmationQuestion->setHidden(true);
        $confirmationQuestion->setHiddenFallback(false);
        $confirmationQuestion->setTrimmable(false);
        $confirmation = $helper->ask($input, $output, $confirmationQuestion);

        if (!is_string($confirmation) || !hash_equals($password, $confirmation)) {
            $output->writeln('<error>The password confirmation did not match.</error>');

            return Command::FAILURE;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->flush();

        $output->writeln('<info>Administrator password updated. You can now sign in.</info>');

        return Command::SUCCESS;
    }
}
